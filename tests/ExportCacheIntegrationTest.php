<?php declare(strict_types=1);

use Cache\IntegrationTests\SimpleCacheTest;
use MiMatus\ExportCache\ExportCache;

class ExportCacheIntegrationTest extends SimpleCacheTest
{

    protected $skippedTests = [
            // 'testSet' => 'Skipped',
            // 'testSetMultiple' => 'Skipped',
            // 'testSetTtl' => 'Skipped',
            // 'testSetExpiredTtl' => 'Skipped',
            // 'testGet' => 'Skipped',
            // 'testDelete' => 'Skipped',
            // 'testClear' => 'Skipped',
            'testSetMultipleWithIntegerArrayKey' => 'Skipped PHP stric typing takes care of it',
            // 'testSetMultipleTtl' => 'Skipped',
            // 'testSetMultipleExpiredTtl' => 'Skipped',
            // 'testSetMultipleWithGenerator' => 'Skipped',
            // 'testGetMultiple' => 'Skipped',
            // 'testGetMultipleWithGenerator' => 'Skipped',
            // 'testDeleteMultiple' => 'Skipped',
            // 'testDeleteMultipleGenerator' => 'Skipped',
            // 'testHas' => 'Skipped',
            // 'testBasicUsageWithLongKey' => 'Skipped',
            'testGetInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testGetMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testGetMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testSetInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testSetMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testSetMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testHasInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testSetInvalidTtl' => 'Skipped - PHP stric typing takes care of it',
            'testSetMultipleInvalidTtl' => 'Skipped  PHP stric typing takes care of it',
            // 'testNullOverwrite' => 'Skipped',
            // 'testDataTypeString' => 'Skipped',
            // 'testDataTypeInteger' => 'Skipped',
            // 'testDataTypeFloat' => 'Skipped',
            // 'testDataTypeBoolean' => 'Skipped',
            // 'testDataTypeArray' => 'Skipped',
            // 'testDataTypeObject' => 'Skipped',
            // 'testBinaryData' => 'Skipped',
            // 'testSetValidKeys' => 'Skipped',
            // 'testSetMultipleValidKeys' => 'Skipped',
            // 'testSetValidData' => 'Skipped',
            // 'testSetMultipleValidData' => 'Skipped',
            // 'testObjectAsDefaultValue' => 'Skipped',
            // 'testObjectDoesNotChangeInCache' => 'Skipped',
    ];

    public function createSimpleCache()
    {
        return new ExportCache(__DIR__.DIRECTORY_SEPARATOR.'data');
    }

}