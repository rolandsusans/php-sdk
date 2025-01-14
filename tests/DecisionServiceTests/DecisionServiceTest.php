<?php
/**
 * Copyright 2017-2021, Optimizely
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Optimizely\Tests;

use Exception;
use Monolog\Logger;
use Optimizely\Bucketer;
use Optimizely\Config\DatafileProjectConfig;
use Optimizely\DecisionService\DecisionService;
use Optimizely\DecisionService\FeatureDecision;
use Optimizely\Entity\Experiment;
use Optimizely\Entity\Variation;
use Optimizely\ErrorHandler\NoOpErrorHandler;
use Optimizely\Logger\DefaultLogger;
use Optimizely\Logger\NoOpLogger;
use Optimizely\Optimizely;
use Optimizely\OptimizelyUserContext;
use Optimizely\UserProfile\UserProfileServiceInterface;
use Optimizely\Utils\Validator;

class DecisionServiceTest extends \PHPUnit_Framework_TestCase
{
    private $bucketerMock;
    private $config;
    private $decisionService;
    private $decisionServiceMock;
    private $loggerMock;
    private $testUserId;
    private $userProvideServiceMock;
    private $optimizely;
    public function setUp()
    {
        $this->testUserId = 'testUserId';
        $this->testUserIdWhitelisted = 'user1';
        $this->experimentKey = 'test_experiment';
        $this->testBucketingIdControl = 'testBucketingIdControl!';  // generates bucketing number 3741
        $this->testBucketingIdVariation = '123456789'; // generates bucketing number 4567
        $this->variationKeyControl = 'control';
        $this->variationKeyVariation = 'variation';
        $this->testUserAttributes = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco'
        ];

        // Mock Logger
        $this->loggerMock = $this->getMockBuilder(NoOpLogger::class)
            ->setMethods(array('log'))
            ->getMock();

        $this->collectedLogs = [];
        $this->collectLogsForAssertion = function ($a, $b) {
            $this->collectedLogs[] = array($a,$b);
        };

        $this->config = new DatafileProjectConfig(DATAFILE, $this->loggerMock, new NoOpErrorHandler());

        // Mock bucketer
        $this->bucketerMock = $this->getMockBuilder(Bucketer::class)
            ->setConstructorArgs(array($this->loggerMock))
            ->setMethods(array('bucket'))
            ->getMock();

        // Mock user profile service implementation
        $this->userProvideServiceMock = $this->getMockBuilder(UserProfileServiceInterface::class)
            ->getMock();

        $this->decisionService = new DecisionService($this->loggerMock);

        $this->decisionServiceMock = $this->getMockBuilder(DecisionService::class)
            ->setConstructorArgs(array($this->loggerMock))
            ->setMethods(array('getVariation'))
            ->getMock();
        $this->optimizely = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);
    }

    public function compareFeatureDecisionsExceptReasons(FeatureDecision $expectedObj, FeatureDecision $actualObj)
    {
        $this->assertEquals($expectedObj->getVariation(), $actualObj->getVariation());
        $this->assertEquals($expectedObj->getExperiment(), $actualObj->getExperiment());
        $this->assertEquals($expectedObj->getSource(), $actualObj->getSource());
    }

    public function testGetVariationReturnsNullWhenExperimentIsNotRunning()
    {
        $this->bucketerMock->expects($this->never())
            ->method('bucket');

        $pausedExperiment = $this->config->getExperimentFromKey('paused_experiment');

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $pausedExperiment, $this->optimizely->createUserContext($this->testUserId));

        $this->assertNull($variation);
    }

    public function testGetVariationBucketsUserWhenExperimentIsRunning()
    {
        $optimizely = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        $expectedVariation = new Variation('7722370027', 'control');
        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($this->testUserId, $this->testUserAttributes));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );
    }

    public function testGetBucketingIdWhenBucketingIdIsNotString()
    {
        $decisionService = new DecisionTester($this->loggerMock, $this->userProvideServiceMock);
        $userAttributesWithBucketingId = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco',
            '$opt_bucketing_id' => 5
        ];

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(Logger::WARNING, 'Bucketing ID attribute is not a string. Defaulted to user ID.');
        
        $expectedReasons = ['Bucketing ID attribute is not a string. Defaulted to user ID.'];

        $this->assertEquals(
            [$this->testUserId, $expectedReasons],
            $decisionService->getBucketingId($this->testUserId, $userAttributesWithBucketingId)
        );
    }

    public function testGetBucketingIdWhenBucketingIdIsNull()
    {
        $decisionService = new DecisionTester($this->loggerMock, $this->userProvideServiceMock);
        $userAttributesWithBucketingId = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco',
            '$opt_bucketing_id' => null
        ];

        $this->loggerMock->expects($this->never())
            ->method('log');

        $this->assertEquals([$this->testUserId, []], $decisionService->getBucketingId($this->testUserId, $userAttributesWithBucketingId));
    }

    public function testGetBucketingIdWhenBucketingIdIsString()
    {
        $decisionService = new DecisionTester($this->loggerMock, $this->userProvideServiceMock);
        $userAttributesWithBucketingId = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco',
            '$opt_bucketing_id' => 'i_am_bucketing_id'
        ];

        $this->loggerMock->expects($this->never())
            ->method('log');

        $this->assertEquals(['i_am_bucketing_id', []], $decisionService->getBucketingId($this->testUserId, $userAttributesWithBucketingId));
    }

    public function testGetBucketingIdWhenBucketingIdIsEmptyString()
    {
        $decisionService = new DecisionTester($this->loggerMock, $this->userProvideServiceMock);
        $userAttributesWithBucketingId = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco',
            '$opt_bucketing_id' => ''
        ];

        $this->loggerMock->expects($this->never())
            ->method('log');

        $this->assertEquals(['', []], $decisionService->getBucketingId($this->testUserId, $userAttributesWithBucketingId));
    }

    public function testGetVariationReturnsWhitelistedVariation()
    {
        $expectedVariation = new Variation('7722370027', 'control');
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        $callIndex = 0;
        $this->bucketerMock->expects($this->never())
            ->method('bucket');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, 'User "user1" is not in the forced variation map.');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::INFO, 'User "user1" is forced in variation "control" of experiment "test_experiment".');

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext('user1'));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );
    }

    public function testGetVariationReturnsWhitelistedVariationForGroupedExperiment()
    {
        $expectedVariation = new Variation(
            '7722260071',
            'group_exp_1_var_1',
            true,
            [
                [
                  "id" => "155563",
                  "value" => "groupie_1_v1"
                ]
            ]
        );
        $runningExperiment = $this->config->getExperimentFromKey('group_experiment_1');

        $callIndex = 0;
        $this->bucketerMock->expects($this->never())
            ->method('bucket');
        
        $forcedVarMessage = 'User "user1" is not in the forced variation map.';
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, $forcedVarMessage);
        
        $whitelistedVarMessage = 'User "user1" is forced in variation "group_exp_1_var_1" of experiment "group_experiment_1".';
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::INFO, $whitelistedVarMessage);

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext('user1'));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );

        $this->assertNotContains($forcedVarMessage, $reasons);
        $this->assertContains($whitelistedVarMessage, $reasons);
        $this->assertCount(1, $reasons);
    }

    public function testGetVariationBucketsWhenForcedVariationsIsEmpty()
    {
        $expectedVariation = new Variation('7722370027', 'control');
        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        // empty out the forcedVariations property
        $experiment = new \ReflectionProperty(Experiment::class, '_forcedVariations');
        $experiment->setAccessible(true);
        $experiment->setValue($runningExperiment, array());

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext('user1', $this->testUserAttributes));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );
    }

    public function testGetVariationBucketsWhenWhitelistedVariationIsInvalid()
    {
        $expectedVariation = new Variation('7722370027', 'control');
        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        // modify the forcedVariation to point to invalid variation
        $experiment = new \ReflectionProperty(Experiment::class, '_forcedVariations');
        $experiment->setAccessible(true);
        $experiment->setValue(
            $runningExperiment,
            [
            'user_1' => 'invalid'
            ]
        );

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext('user1', $this->testUserAttributes));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );
    }

    public function testGetVariationBucketsUserWhenUserIsNotWhitelisted()
    {
        $expectedVariation = new Variation('7722370027', 'control');
        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext('not_whitelisted_user', $this->testUserAttributes));

        $this->assertEquals(
            $expectedVariation,
            $variation
        );
    }

    public function testGetVariationReturnsNullIfUserDoesNotMeetAudienceConditions()
    {
        $optimizely = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        $this->bucketerMock->expects($this->never())
            ->method('bucket');

        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($this->testUserId)); // no matching attributes

        $this->assertNull($variation);
    }

    public function testGetVariationReturnsStoredVariationIfAvailable()
    {
        $userId = 'not_whitelisted_user';
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');
        $expectedVariation = new Variation('7722370027', 'control');

        $callIndex = 0;
        $this->bucketerMock->expects($this->never())
            ->method('bucket');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, 'User "not_whitelisted_user" is not in the forced variation map.');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::INFO, 'Returning previously activated variation "control" of experiment "test_experiment" for user "not_whitelisted_user" from user profile.');

        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array(
                '7716830082' => array(
                    'variation_id' => '7722370027'
                )
            )
        );
        $this->userProvideServiceMock->expects($this->once())
            ->method('lookup')
            ->willReturn($storedUserProfile);

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($userId));
        $this->assertEquals($expectedVariation, $variation);
    }

    public function testGetVariationBucketsIfNoStoredVariation()
    {
        $optimizely = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        $userId = $this->testUserId;
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');
        $expectedVariation = new Variation('7722370027', 'control');

        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $this->loggerMock->expects($this->any())
            ->method('log')
            ->will($this->returnCallback($this->collectLogsForAssertion));

        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array()
        );
        $this->userProvideServiceMock->expects($this->once())
            ->method('lookup')
            ->willReturn($storedUserProfile);

        $this->userProvideServiceMock->expects($this->once())
            ->method('save')
            ->with(
                array(
                'user_id' => $userId,
                'experiment_bucket_map' => array(
                    '7716830082' => array(
                        'variation_id' => '7722370027'
                    )
                )
                )
            );

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($userId, $this->testUserAttributes));
        $this->assertEquals($expectedVariation, $variation);

        // Verify Logs
        $this->assertContains([Logger::DEBUG, sprintf('User "%s" is not in the forced variation map.', $userId)], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'No previously activated variation of experiment "test_experiment" for user "testUserId" found in user profile.'], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'Saved variation "control" of experiment "test_experiment" for user "testUserId".'], $this->collectedLogs);
    }

    public function testGetVariationBucketsIfStoredVariationIsInvalid()
    {
        $userId = $this->testUserId;
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');
        $expectedVariation = new Variation('7722370027', 'control');

        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $this->loggerMock->expects($this->any())
            ->method('log')
            ->will($this->returnCallback($this->collectLogsForAssertion));

        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array(
                '7716830082' => array(
                    'variation_id' => 'invalid'
                )
            )
        );
        $this->userProvideServiceMock->expects($this->once())
            ->method('lookup')
            ->willReturn($storedUserProfile);

        $this->userProvideServiceMock->expects($this->once())
            ->method('save')
            ->with(
                array(
                'user_id' => $userId,
                'experiment_bucket_map' => array(
                    '7716830082' => array(
                        'variation_id' => '7722370027'
                    )
                )
                )
            );

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($userId, $this->testUserAttributes));
        $this->assertEquals($expectedVariation, $variation);

        // Verify Logs
        $this->assertContains([Logger::DEBUG, sprintf('User "%s" is not in the forced variation map.', $userId)], $this->collectedLogs);

        $userProfileMsg = 'User "testUserId" was previously bucketed into variation with ID "invalid" for experiment "test_experiment", but no matching variation was found for that user. We will re-bucket the user.';
        $this->assertContains([Logger::INFO, $userProfileMsg], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'Saved variation "control" of experiment "test_experiment" for user "testUserId".'], $this->collectedLogs);

        $this->assertContains($userProfileMsg, $reasons);
    }

    public function testGetVariationBucketsIfUserProfileServiceLookupThrows()
    {
        $optimizely = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        $userId = $this->testUserId;
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');
        $expectedVariation = new Variation('7722370027', 'control');

        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $this->loggerMock->expects($this->any())
            ->method('log')
            ->will($this->returnCallback($this->collectLogsForAssertion));

        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array(
                '7716830082' => array(
                    'variation_id' => 'invalid'
                )
            )
        );
        $this->userProvideServiceMock
            ->method('lookup')
            ->will($this->throwException(new Exception('I am error')));

        $this->userProvideServiceMock->expects($this->once())
            ->method('save')
            ->with(
                array(
                'user_id' => $userId,
                'experiment_bucket_map' => array(
                    '7716830082' => array(
                        'variation_id' => '7722370027'
                    )
                )
                )
            );

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($userId, $this->testUserAttributes));
        $this->assertEquals($expectedVariation, $variation);

        // Verify Logs
        $this->assertContains([Logger::DEBUG, sprintf('User "%s" is not in the forced variation map.', $userId)], $this->collectedLogs);
        
        $lookupFailedMsg = 'The User Profile Service lookup method failed: I am error.';
        $this->assertContains([Logger::ERROR, $lookupFailedMsg], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'Saved variation "control" of experiment "test_experiment" for user "testUserId".'], $this->collectedLogs);
    
        $this->assertContains($lookupFailedMsg, $reasons);
    }

    public function testGetVariationBucketsIfUserProfileServiceSaveThrows()
    {
        $userId = $this->testUserId;
        $runningExperiment = $this->config->getExperimentFromKey('test_experiment');
        $expectedVariation = new Variation('7722370027', 'control');

        $this->bucketerMock->expects($this->once())
            ->method('bucket')
            ->willReturn([$expectedVariation, []]);

        $this->loggerMock->expects($this->any())
            ->method('log')
            ->will($this->returnCallback($this->collectLogsForAssertion));

        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array(
                '7716830082' => array(
                    'variation_id' => 'invalid'
                )
            )
        );
        $this->userProvideServiceMock->expects($this->once())
            ->method('lookup')
            ->willReturn(null);

        $this->userProvideServiceMock
            ->method('save')
            ->with($this->throwException(new Exception()));

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        list($variation, $reasons) = $this->decisionService->getVariation($this->config, $runningExperiment, $this->optimizely->createUserContext($userId, $this->testUserAttributes));
        $this->assertEquals($expectedVariation, $variation);

        // Verify Logs
        $this->assertContains([Logger::DEBUG, sprintf('User "%s" is not in the forced variation map.', $userId)], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'No user profile found for user with ID "testUserId".'], $this->collectedLogs);
        $this->assertContains([Logger::WARNING, 'Failed to save variation "control" of experiment "test_experiment" for user "testUserId".'], $this->collectedLogs);
    }

    public function testGetVariationUserWithSetForcedVariation()
    {
        $experimentKey = 'test_experiment';
        $pausedExperimentKey = 'paused_experiment';
        $userId = 'test_user';
        $bucketedVariationKey = 'control';

        $optlyObject = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        $userAttributes = [
            'device_type' => 'iPhone',
            'location' => 'San Francisco'
        ];

        $optlyObject->activate($experimentKey, $userId, $userAttributes);

        // confirm normal bucketing occurs before setting the forced variation
        $forcedVariationKey = $optlyObject->getVariation($experimentKey, $userId, $userAttributes);
        $this->assertEquals($bucketedVariationKey, $forcedVariationKey);

        // test valid experiment
        $this->assertTrue($optlyObject->setForcedVariation($experimentKey, $userId, $forcedVariationKey), sprintf('Set variation to "%s" failed.', $forcedVariationKey));
        $forcedVariationKey = $optlyObject->getVariation($experimentKey, $userId, $userAttributes);
        $this->assertEquals($forcedVariationKey, $forcedVariationKey);

        // clear forced variation and confirm that normal bucketing occurs
        $this->assertTrue($optlyObject->setForcedVariation($experimentKey, $userId, null), sprintf('Set variation to "%s" failed.', $forcedVariationKey));
        $forcedVariationKey = $optlyObject->getVariation($experimentKey, $userId, $userAttributes);
        $this->assertEquals($bucketedVariationKey, $forcedVariationKey);

        // check that a paused experiment returns null
        $this->assertTrue($optlyObject->setForcedVariation($pausedExperimentKey, $userId, 'variation'), sprintf('Set variation to "%s" failed.', $forcedVariationKey));
        $forcedVariationKey = $optlyObject->getVariation($pausedExperimentKey, $userId, $userAttributes);
        $this->assertNull($forcedVariationKey);
    }

    public function testGetVariationWithBucketingId()
    {
        $pausedExperimentKey = 'paused_experiment';
        $userId = 'test_user';

        $userAttributesWithBucketingId = [
            'device_type' => 'iPhone',
            'company' => 'Optimizely',
            'location' => 'San Francisco',
            '$opt_bucketing_id' => $this->testBucketingIdVariation
        ];

        $invalidUserAttributesWithBucketingId = [
            'company' => 'Optimizely',
            '$opt_bucketing_id' => $this->testBucketingIdControl
        ];

        $optlyObject = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);

        // confirm normal bucketing occurs before setting the bucketing ID
        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, $this->testUserAttributes);
        $this->assertEquals($this->variationKeyControl, $variationKey);

        // confirm valid bucketing with bucketing ID set in attributes
        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, $userAttributesWithBucketingId);
        $this->assertEquals($this->variationKeyVariation, $variationKey);

        // check invalid audience with bucketing ID
        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, $invalidUserAttributesWithBucketingId);
        $this->assertNull($variationKey);

        // check null audience with bucketing Id
        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, null);
        $this->assertNull($variationKey);

        // test that an experiment that's not running returns a null variation
        $variationKey = $optlyObject->getVariation($pausedExperimentKey, $userId, $userAttributesWithBucketingId);
        $this->assertNull($variationKey);

        // check forced variation
        $this->assertTrue($optlyObject->setForcedVariation($this->experimentKey, $userId, $this->variationKeyControl), sprintf('Set variation to "%s" failed.', $this->variationKeyControl));
        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, $userAttributesWithBucketingId);
        $this->assertEquals($this->variationKeyControl, $variationKey);

        // check whitelisted variation
        $variationKey = $optlyObject->getVariation($this->experimentKey, $this->testUserIdWhitelisted, $userAttributesWithBucketingId);
        $this->assertEquals($this->variationKeyControl, $variationKey);

        // check user profile
        $storedUserProfile = array(
            'user_id' => $userId,
            'experiment_bucket_map' => array(
                '7716830082' => array(
                    'variation_id' => '7722370027'
                )
            )
        );
        $this->userProvideServiceMock
            ->method('lookup')
            ->willReturn($storedUserProfile);

        $this->decisionService = new DecisionService($this->loggerMock, $this->userProvideServiceMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        $variationKey = $optlyObject->getVariation($this->experimentKey, $userId, $userAttributesWithBucketingId);
        $this->assertEquals($this->variationKeyControl, $variationKey, sprintf('Variation "%s" does not match expected user profile variation "%s".', $variationKey, $this->variationKeyControl));
    }

    // should return nil and log a message when the feature flag's experiment ids array is empty
    public function testGetVariationForFeatureExperimentGivenNullExperimentIds()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('empty_feature');
        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(Logger::DEBUG, "The feature flag 'empty_feature' is not used in any experiments.");

        $actualDecision = $this->decisionServiceMock->getVariationForFeatureExperiment($this->config, $featureFlag, $this->optimizely->createUserContext('user1', []));
        $this->assertNull($actualDecision->getVariation());
    }

    // should return nil and log a message when the experiment is not in the datafile
    public function testGetVariationForFeatureExperimentGivenExperimentNotInDataFile()
    {
        $boolean_feature = $this->config->getFeatureFlagFromKey('boolean_feature');
        $featureFlag = clone $boolean_feature;
        // Use any string that is not an experiment id in the data file
        $featureFlag->setExperimentIds(["29039203"]);

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(Logger::ERROR, 'Experiment ID "29039203" is not in datafile.');

        $this->loggerMock->expects($this->at(1))
            ->method('log')
            ->with(
                Logger::INFO,
                "The user 'user1' is not bucketed into any of the experiments using the feature 'boolean_feature'."
            );

        $actualDecision = $this->decisionServiceMock->getVariationForFeatureExperiment($this->config, $featureFlag, $this->optimizely->createUserContext('user1', []));
        $this->assertNull($actualDecision->getVariation());
    }

    // should return nil and log when the user is not bucketed into the feature flag's experiments
    public function testGetVariationForFeatureExperimentGivenNonMutexGroupAndUserNotBucketed()
    {
        $multivariate_experiment = $this->config->getExperimentFromKey('test_experiment_multivariate');

        // make sure the user is not bucketed into the feature experiment
        $this->decisionServiceMock->expects($this->at(0))
            ->method('getVariation')
            ->will($this->returnValue([ null, []]));

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "The user 'user1' is not bucketed into any of the experiments using the feature 'multi_variate_feature'."
            );
        $featureFlag = $this->config->getFeatureFlagFromKey('multi_variate_feature');

        $actualDecision = $this->decisionServiceMock->getVariationForFeatureExperiment($this->config, $featureFlag, $this->optimizely->createUserContext('user1', []));
        $this->assertNull($actualDecision->getVariation());
    }

    //  should return the variation when the user is bucketed into a variation for the experiment on the feature flag
    public function testGetVariationForFeatureExperimentGivenNonMutexGroupAndUserIsBucketed()
    {
        // return the first variation of the `test_experiment_multivariate` experiment, which is attached to the `multi_variate_feature`
        $experiment = $this->config->getExperimentFromKey('test_experiment_multivariate');
        $variation = $this->config->getVariationFromId('test_experiment_multivariate', '122231');
        $this->decisionServiceMock->expects($this->at(0))
            ->method('getVariation')
            ->will($this->returnValue([$variation, []]));

        $featureFlag = $this->config->getFeatureFlagFromKey('multi_variate_feature');
        $expected_decision = new FeatureDecision($experiment, $variation, FeatureDecision::DECISION_SOURCE_FEATURE_TEST);

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "The user 'user_1' is bucketed into experiment 'test_experiment_multivariate' of feature 'multi_variate_feature'."
            );

        $actualDecision = $this->decisionServiceMock->getVariationForFeatureExperiment($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', []));
        $this->compareFeatureDecisionsExceptReasons($expected_decision, $actualDecision);
    }

    // should return the variation the user is bucketed into when the user is bucketed into one of the experiments
    public function testGetVariationForFeatureExperimentGivenMutexGroupAndUserIsBucketed()
    {
        $mutex_exp = $this->config->getExperimentFromKey('group_experiment_1');
        $variation = $mutex_exp->getVariations()[0];
        $this->decisionServiceMock->expects($this->at(0))
            ->method('getVariation')
            ->will($this->returnValue([$variation, []]));

        $mutex_exp = $this->config->getExperimentFromKey('group_experiment_1');
        $variation = $mutex_exp->getVariations()[0];
        $expected_decision = new FeatureDecision($mutex_exp, $variation, FeatureDecision::DECISION_SOURCE_FEATURE_TEST);

        $featureFlag = $this->config->getFeatureFlagFromKey('mutex_group_feature');
        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "The user 'user_1' is bucketed into experiment 'group_experiment_1' of feature 'mutex_group_feature'."
            );

        $actualDecision = $this->decisionServiceMock->getVariationForFeatureExperiment($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', []));
        $this->compareFeatureDecisionsExceptReasons($expected_decision, $actualDecision);
    }

    // should return nil and log a message when the user is not bucketed into any of the mutex experiments
    public function testGetVariationForFeatureExperimentGivenMutexGroupAndUserNotBucketed()
    {
        $mutex_exp = $this->config->getExperimentFromKey('group_experiment_1');
        $variation = $mutex_exp->getVariations()[0];
        $this->decisionServiceMock->expects($this->at(0))
            ->method('getVariation')
            ->will($this->returnValue([null, []]));


        $mutex_exp = $this->config->getExperimentFromKey('group_experiment_1');
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_feature');
        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "The user 'user_1' is not bucketed into any of the experiments using the feature 'boolean_feature'."
            );

        $actualFeatureDecision = $this->decisionServiceMock->getVariationForFeatureExperiment(
            $this->config,
            $featureFlag,
            $this->optimizely->createUserContext('user_1', [])
        );
        $this->assertNull($actualFeatureDecision->getVariation());
    }

    // should return the bucketed experiment and variation
    public function testGetVariationForFeatureWhenTheUserIsBucketedIntoFeatureExperiment()
    {
        $decisionServiceMock = $this->getMockBuilder(DecisionService::class)
            ->setConstructorArgs(array($this->loggerMock))
            ->setMethods(array('getVariationForFeatureExperiment'))
            ->getMock();

        $featureFlag = $this->config->getFeatureFlagFromKey('string_single_variable_feature');
        $expected_experiment_id = $featureFlag->getExperimentIds()[0];
        $expected_experiment = $this->config->getExperimentFromId($expected_experiment_id);
        $expected_variation = $expected_experiment->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $expected_experiment,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_FEATURE_TEST
        );

        $decisionServiceMock->expects($this->at(0))
            ->method('getVariationForFeatureExperiment')
            ->will($this->returnValue($expected_decision));

        $this->assertEquals(
            $expected_decision,
            $decisionServiceMock->getVariationForFeature($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', []))
        );
    }

    // should return the bucketed variation and null experiment
    public function testGetVariationForFeatureWhenBucketedToFeatureRollout()
    {
        $decisionServiceMock = $this->getMockBuilder(DecisionService::class)
            ->setConstructorArgs(array($this->loggerMock))
            ->setMethods(array('getVariationForFeatureExperiment','getVariationForFeatureRollout'))
            ->getMock();

        $featureFlag = $this->config->getFeatureFlagFromKey('string_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment = $rollout->getExperiments()[0];
        $expected_variation = $experiment->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $experiment,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_ROLLOUT
        );

        $decisionServiceMock
            ->method('getVariationForFeatureExperiment')
            ->will($this->returnValue(new FeatureDecision(null, null, null)));

        $decisionServiceMock
            ->method('getVariationForFeatureRollout')
            ->will($this->returnValue($expected_decision));

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "User 'user_1' is bucketed into rollout for feature flag 'string_single_variable_feature'."
            );

        $actualFeatureDecision = $decisionServiceMock->getVariationForFeature($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', []));
        $this->compareFeatureDecisionsExceptReasons($expected_decision, $actualFeatureDecision);
    }

    // should return null
    public function testGetVariationForFeatureWhenTheUserIsNeitherBucketedIntoFeatureExperimentNorToFeatureRollout()
    {
        $decisionServiceMock = $this->getMockBuilder(DecisionService::class)
            ->setConstructorArgs(array($this->loggerMock))
            ->setMethods(array('getVariationForFeatureExperiment','getVariationForFeatureRollout'))
            ->getMock();

        $featureFlag = $this->config->getFeatureFlagFromKey('string_single_variable_feature');

        $decisionServiceMock
            ->method('getVariationForFeatureExperiment')
            ->will($this->returnValue(new FeatureDecision(null, null, null)));

        $decisionServiceMock
            ->method('getVariationForFeatureRollout')
            ->will($this->returnValue(new FeatureDecision(null, null, null)));

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::INFO,
                "User 'user_1' is not bucketed into rollout for feature flag 'string_single_variable_feature'."
            );

        // mock getVariationForFeature to return rolloutDecision
        $expectedDecision = new FeatureDecision(
            null,
            null,
            FeatureDecision::DECISION_SOURCE_ROLLOUT
        );

        $actualFeatureDecision = $decisionServiceMock->getVariationForFeature($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', []));

        $this->compareFeatureDecisionsExceptReasons($expectedDecision, $actualFeatureDecision);
    }

    // should return null
    public function testGetVariationForFeatureRolloutWhenNoRolloutIsAssociatedToFeatureFlag()
    {
        // No rollout id is associated to boolean_feature
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_feature');

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::DEBUG,
                "Feature flag 'boolean_feature' is not used in a rollout."
            );

        $actualFeatureDecision = $this->decisionServiceMock->getVariationForFeatureRollout(
            $this->config,
            $featureFlag,
            $this->optimizely->createUserContext('user_1', [])
        );
        $this->assertNull($actualFeatureDecision->getVariation());
    }

    // should return null
    public function testGetVariationForFeatureRolloutWhenRolloutIsNotInDataFile()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_feature');
        $featureFlag = clone $featureFlag;
        // Set any string which is not a rollout id in the data file
        $featureFlag->setRolloutId('invalid_rollout_id');

        $this->loggerMock->expects($this->at(0))
            ->method('log')
            ->with(
                Logger::ERROR,
                'Rollout with ID "invalid_rollout_id" is not in the datafile.'
            );

        $actualFeatureDecision = $this->decisionServiceMock->getVariationForFeatureRollout(
            $this->config,
            $featureFlag,
            $this->optimizely->createUserContext('user_1', [])
        );
        $this->assertNull($actualFeatureDecision->getVariation());
    }

    // should return null
    public function testGetVariationForFeatureRolloutWhenRolloutDoesNotHaveExperiment()
    {
        // Mock Project Config
        $configMock = $this->getMockBuilder(DatafileProjectConfig::class)
            ->setConstructorArgs(array(DATAFILE, $this->loggerMock, new NoOpErrorHandler()))
            ->setMethods(array('getRolloutFromId'))
            ->getMock();

        $this->decisionService = new DecisionService($this->loggerMock);

        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment_less_rollout = clone $rollout;
        $experiment_less_rollout->setExperiments([]);

        $configMock
            ->method('getRolloutFromId')
            ->will($this->returnValue($experiment_less_rollout));

        $actualFeatureDecision = $this->decisionService->getVariationForFeatureRollout($configMock, $featureFlag, $this->optimizely->createUserContext('user_1', []));
        $this->assertNull($actualFeatureDecision->getVariation());
    }

    // ============== when the user qualifies for targeting rule (audience match) ======================

    //  should return the variation the user is bucketed into when the user is bucketed into the targeting rule
    public function testGetVariationForFeatureRolloutWhenUserIsBucketedInTheTargetingRule()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment = $rollout->getExperiments()[0];
        $expected_variation = $experiment->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $experiment,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_ROLLOUT
        );

        // Provide attributes such that user qualifies for audience
        $user_attributes = ["browser_type" => "chrome"];

        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        $this->bucketerMock
            ->method('bucket')
            ->willReturn([$expected_variation, []]);

        $this->compareFeatureDecisionsExceptReasons(
            $expected_decision,
            $this->decisionService->getVariationForFeatureRollout($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', $user_attributes))
        );
    }

    // should return the variation the user is bucketed into when the user is bucketed into the "Everyone Else" rule'
    // and the user is not bucketed into the targeting rule
    public function testGetVariationForFeatureRolloutWhenUserIsNotBucketedInTheTargetingRuleButBucketedToEveryoneElseRule()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment0 = $rollout->getExperiments()[0];
        // Everyone Else Rule
        $experiment2 = $rollout->getExperiments()[2];
        $expected_variation = $experiment2->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $experiment2,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_ROLLOUT,
            [
                'Audiences for rule 1 collectively evaluated to TRUE.',
                'User "user_1" meets condition for targeting rule "1".',
                'User "user_1" is not in the traffic group for targeting rule "1". Checking Everyone Else rule now.',
                'Audiences for rule Everyone Else collectively evaluated to TRUE.',
                'User "user_1" meets condition for targeting rule "Everyone Else".',
                'User "user_1" is in the traffic group of targeting rule "Everyone Else".'
            ]
        );

        // Provide attributes such that user qualifies for audience
        $user_attributes = ["browser_type" => "chrome"];
        $this->decisionService = new DecisionService($this->loggerMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);
        // Make bucket return null when called for first targeting rule
        $this->bucketerMock->expects($this->at(0))
            ->method('bucket')
            ->willReturn([null, []]);
        // Make bucket return expected variation when called second time for everyone else
        $this->bucketerMock->expects($this->at(1))
            ->method('bucket')
            ->willReturn([$expected_variation, []]);

        $this->assertEquals(
            $expected_decision,
            $this->decisionService->getVariationForFeatureRollout($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', $user_attributes))
        );
    }

    // should log and return nil when  the user is not bucketed into the targeting rule and
    // the user is not bucketed into the "Everyone Else" rule'
    public function testGetVariationForFeatureRolloutWhenUserIsNeitherBucketedInTheTargetingRuleNorToEveryoneElseRule()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment0 = $rollout->getExperiments()[0];
        // Everyone Else Rule
        $experiment2 = $rollout->getExperiments()[2];

        // Provide attributes such that user qualifies for audience
        $user_attributes = ["browser_type" => "chrome"];
        $this->decisionService = new DecisionService($this->loggerMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);
        // Make bucket return null when called for first targeting rule
        $this->bucketerMock->expects($this->at(0))
            ->method('bucket')
            ->willReturn([null, []]);
        // Make bucket return null when called second time for everyone else
        $this->bucketerMock->expects($this->at(1))
            ->method('bucket')
            ->willReturn([null, []]);

        $actualFeatureDecision = $this->decisionService->getVariationForFeatureRollout(
            $this->config,
            $featureFlag,
            $this->optimizely->createUserContext('user_1', $user_attributes)
        );
        
        $this->assertNull($actualFeatureDecision->getVariation());
    }

    // ============== END of tests - when the user qualifies for targeting rule (audience match) ======================

    // ===== - when the user does not qualify for the tageting rules (audience mismatch) ======

    // should return expected variation when the user is attempted to be bucketed into all targeting rules
    // including Everyone Else rule
    public function testGetVariationForFeatureRolloutWhenUserDoesNotQualifyForAnyTargetingRule()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment0 = $rollout->getExperiments()[0];
        $experiment1 = $rollout->getExperiments()[1];
        // Everyone Else Rule
        $experiment2 = $rollout->getExperiments()[2];
        $expected_variation = $experiment2->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $experiment2,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_ROLLOUT,
            [
                'Audiences for rule 1 collectively evaluated to FALSE.' ,
                'User "user_1" does not meet conditions for targeting rule "1".',
                'Audiences for rule 2 collectively evaluated to FALSE.',
                'User "user_1" does not meet conditions for targeting rule "2".',
                'Audiences for rule Everyone Else collectively evaluated to TRUE.',
                'User "user_1" meets condition for targeting rule "Everyone Else".',
                'User "user_1" is in the traffic group of targeting rule "Everyone Else".'
            ]
        );

        // Provide null attributes so that user does not qualify for audience
        $user_attributes = [];
        $this->decisionService = new DecisionService($this->loggerMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        // Expect bucket to be called exactly once for the everyone else/last rule.
        $this->bucketerMock->expects($this->exactly(1))
            ->method('bucket')
            ->willReturn([$expected_variation, []]);

        $this->loggerMock->expects($this->any())
                        ->method('log')
                        ->will($this->returnCallback($this->collectLogsForAssertion));

        $this->assertEquals(
            $expected_decision,
            $this->decisionService->getVariationForFeatureRollout($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', $user_attributes))
        );

        // Verify Logs
        $this->assertContains([Logger::DEBUG, 'User "user_1" does not meet conditions for targeting rule "1".'], $this->collectedLogs);
        $this->assertContains([Logger::DEBUG, 'User "user_1" does not meet conditions for targeting rule "2".'], $this->collectedLogs);
    }

    public function testGetVariationForFeatureRolloutWhenUserDoesNotQualifyForAnyTargetingRuleOrEveryoneElseRule()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment0 = $rollout->getExperiments()[0];
        $experiment1 = $rollout->getExperiments()[1];
        // Everyone Else Rule
        $experiment2 = $rollout->getExperiments()[2];

        // Set an AudienceId for everyone else/last rule so that user does not qualify for audience
        $experiment2->setAudienceIds(["11155"]);
        $expected_variation = $experiment2->getVariations()[0];

        // Provide null attributes so that user does not qualify for audience
        $user_attributes = [];
        $this->decisionService = new DecisionService($this->loggerMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        // // Expect bucket never called for the everyone else/last rule.
        $this->bucketerMock->expects($this->never())
            ->method('bucket');

        $this->loggerMock->expects($this->any())
            ->method('log')
            ->will($this->returnCallback($this->collectLogsForAssertion));

        $actualFeatureDecision = $this->decisionService->getVariationForFeatureRollout($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', $user_attributes));

        $this->assertNull($actualFeatureDecision->getVariation());

        // Verify Logs
        $this->assertContains([Logger::DEBUG, 'User "user_1" does not meet conditions for targeting rule "1".'], $this->collectedLogs);
        $this->assertContains([Logger::DEBUG, 'User "user_1" does not meet conditions for targeting rule "2".'], $this->collectedLogs);
        $this->assertContains([Logger::DEBUG, 'User "user_1" does not meet conditions for targeting rule "Everyone Else".'], $this->collectedLogs);
    }


    public function testGetVariationForFeatureRolloutLogging()
    {
        $featureFlag = $this->config->getFeatureFlagFromKey('boolean_single_variable_feature');
        $rollout_id = $featureFlag->getRolloutId();
        $rollout = $this->config->getRolloutFromId($rollout_id);
        $experiment0 = $rollout->getExperiments()[0];
        $experiment1 = $rollout->getExperiments()[1];
        // Everyone Else Rule
        $experiment2 = $rollout->getExperiments()[2];
        $expected_variation = $experiment2->getVariations()[0];
        $expected_decision = new FeatureDecision(
            $experiment2,
            $expected_variation,
            FeatureDecision::DECISION_SOURCE_ROLLOUT,
            [
                'Audiences for rule 1 collectively evaluated to FALSE.',
                'User "user_1" does not meet conditions for targeting rule "1".',
                'Audiences for rule 2 collectively evaluated to FALSE.',
                'User "user_1" does not meet conditions for targeting rule "2".',
                'Audiences for rule Everyone Else collectively evaluated to TRUE.',
                'User "user_1" meets condition for targeting rule "Everyone Else".',
                'User "user_1" is in the traffic group of targeting rule "Everyone Else".'
            ]
        );

        // Provide null attributes so that user does not qualify for audience
        $user_attributes = [];
        $this->decisionService = new DecisionService($this->loggerMock);
        $bucketer = new \ReflectionProperty(DecisionService::class, '_bucketer');
        $bucketer->setAccessible(true);
        $bucketer->setValue($this->decisionService, $this->bucketerMock);

        // Expect bucket to be called exactly once for the everyone else/last rule.
        $this->bucketerMock->expects($this->exactly(1))
            ->method('bucket')
            ->willReturn([$expected_variation, []]);

        $this->loggerMock->expects($this->any())
                        ->method('log')
                        ->will($this->returnCallback($this->collectLogsForAssertion));

        $this->assertEquals(
            $expected_decision,
            $this->decisionService->getVariationForFeatureRollout($this->config, $featureFlag, $this->optimizely->createUserContext('user_1', $user_attributes))
        );

        // Verify Logs
        $this->assertContains([Logger::DEBUG, 'Evaluating audiences for rule 1: ["11155"].'], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'Audiences for rule 1 collectively evaluated to FALSE.'], $this->collectedLogs);
        $this->assertContains([Logger::DEBUG, 'Evaluating audiences for rule 2: ["11155"].'], $this->collectedLogs);
        $this->assertContains([Logger::INFO,'Audiences for rule 2 collectively evaluated to FALSE.'], $this->collectedLogs);
        $this->assertContains([Logger::DEBUG, 'Evaluating audiences for rule Everyone Else: [].'], $this->collectedLogs);
        $this->assertContains([Logger::INFO, 'Audiences for rule Everyone Else collectively evaluated to TRUE.'], $this->collectedLogs);
    }


    // test set/get forced variation for the following cases:
    //      - valid and invalid user ID
    //      - valid and invalid experiment key
    //      - valid and invalid variation key, null variation key
    public function testSetGetForcedVariation()
    {
        $userId = 'test_user';
        $invalidUserId = 'invalid_user';
        $experimentKey = 'test_experiment';
        $experimentKey2 = 'group_experiment_1';
        $invalidExperimentKey = 'invalid_experiment';
        $variationKey = 'control';
        $variationKey2 = 'group_exp_1_var_1';
        $invalidVariationKey = 'invalid_variation';

        $optlyObject = new Optimizely(DATAFILE, new ValidEventDispatcher(), $this->loggerMock);
        $userAttributes = [
            'device_type' => 'iPhone',
            'location' => 'San Francisco'
        ];

        $optlyObject->activate('test_experiment', 'test_user', $userAttributes);

        $this->config = new DatafileProjectConfig(DATAFILE, $this->loggerMock, new NoOpErrorHandler());

        // invalid experiment key should return a null variation
        $this->assertFalse($this->decisionService->setForcedVariation($this->config, $invalidExperimentKey, $userId, $variationKey));
        $this->assertNull($this->decisionService->getForcedVariation($this->config, $invalidExperimentKey, $userId)[0]);

        // setting a null variation should return a null variation
        $this->assertTrue($this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, null));
        $this->assertNull($this->decisionService->getForcedVariation($this->config, $experimentKey, $userId)[0]);

        // setting an invalid variation should return a null variation
        $this->assertFalse($this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, $invalidVariationKey));
        $this->assertNull($this->decisionService->getForcedVariation($this->config, $experimentKey, $userId)[0]);

        // confirm the forced variation is returned after a set
        $this->assertTrue($this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, $variationKey));
        $forcedVariation = $this->decisionService->getForcedVariation($this->config, $experimentKey, $userId)[0];
        $this->assertEquals($variationKey, $forcedVariation->getKey());

        // check multiple sets
        $this->assertTrue($this->decisionService->setForcedVariation($this->config, $experimentKey2, $userId, $variationKey2));
        $forcedVariation2 = $this->decisionService->getForcedVariation($this->config, $experimentKey2, $userId)[0];
        $this->assertEquals($variationKey2, $forcedVariation2->getKey());
        // make sure the second set does not overwrite the first set
        $forcedVariation = $this->decisionService->getForcedVariation($this->config, $experimentKey, $userId)[0];
        $this->assertEquals($variationKey, $forcedVariation->getKey());
        // make sure unsetting the second experiment-to-variation mapping does not unset the
        // first experiment-to-variation mapping
        $this->assertTrue($this->decisionService->setForcedVariation($this->config, $experimentKey2, $userId, null));
        $forcedVariation = $this->decisionService->getForcedVariation($this->config, $experimentKey, $userId)[0];
        $this->assertEquals($variationKey, $forcedVariation->getKey());

        // an invalid user ID should return a null variation
        $this->assertNull($this->decisionService->getForcedVariation($this->config, $experimentKey, $invalidUserId)[0]);
    }

    // test that all the logs in setForcedVariation are getting called
    public function testSetForcedVariationLogs()
    {
        $userId = 'test_user';
        $experimentKey = 'test_experiment';
        $experimentId = '7716830082';
        $invalidExperimentKey = 'invalid_experiment';
        $variationKey = 'control';
        $variationId = '7722370027';
        $invalidVariationKey = 'invalid_variation';
        $callIndex = 0;

        $this->loggerMock->expects($this->exactly(5))
            ->method('log');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::ERROR, sprintf('Experiment key "%s" is not in datafile.', $invalidExperimentKey));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, sprintf('Variation mapped to experiment "%s" has been removed for user "%s".', $experimentKey, $userId));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::ERROR, sprintf('Provided %s is in an invalid format.', Optimizely::VARIATION_KEY));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::ERROR, sprintf('No variation key "%s" defined in datafile for experiment "%s".', $invalidVariationKey, $experimentKey));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, sprintf('Set variation "%s" for experiment "%s" and user "%s" in the forced variation map.', $variationId, $experimentId, $userId));

        $this->config = new DatafileProjectConfig(DATAFILE, $this->loggerMock, new NoOpErrorHandler());

        $this->decisionService->setForcedVariation($this->config, $invalidExperimentKey, $userId, $variationKey);
        $this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, null);
        $this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, '');
        $this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, $invalidVariationKey);
        $this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, $variationKey);
    }

    // test that all the logs in getForcedVariation are getting called
    public function testGetForcedVariationLogs()
    {
        $userId = 'test_user';
        $invalidUserId = 'invalid_user';
        $experimentKey = 'test_experiment';
        $experimentId = '7716830082';
        $invalidExperimentKey = 'invalid_experiment';
        $pausedExperimentKey = 'paused_experiment';
        $variationKey = 'control';
        $variationId = '7722370027';
        $callIndex = 0;

        $this->loggerMock->expects($this->exactly(5))
            ->method('log');
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, sprintf('Set variation "%s" for experiment "%s" and user "%s" in the forced variation map.', $variationId, $experimentId, $userId));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, sprintf('User "%s" is not in the forced variation map.', $invalidUserId));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::ERROR, sprintf('Experiment key "%s" is not in datafile.', $invalidExperimentKey));
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, sprintf('No experiment "%s" mapped to user "%s" in the forced variation map.', $pausedExperimentKey, $userId));
        
        $varMappedMsg = sprintf('Variation "%s" is mapped to experiment "%s" and user "%s" in the forced variation map', $variationKey, $experimentKey, $userId);
        $this->loggerMock->expects($this->at($callIndex++))
            ->method('log')
            ->with(Logger::DEBUG, $varMappedMsg);

        $this->config = new DatafileProjectConfig(DATAFILE, $this->loggerMock, new NoOpErrorHandler());

        $this->decisionService->setForcedVariation($this->config, $experimentKey, $userId, $variationKey);
        $this->decisionService->getForcedVariation($this->config, $experimentKey, $invalidUserId);
        $this->decisionService->getForcedVariation($this->config, $invalidExperimentKey, $userId);
        $this->decisionService->getForcedVariation($this->config, $pausedExperimentKey, $userId);
        list($var, $reasons) = $this->decisionService->getForcedVariation($this->config, $experimentKey, $userId);

        $this->assertContains($varMappedMsg, $reasons);
    }
}
