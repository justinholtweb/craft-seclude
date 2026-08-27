<?php

declare(strict_types=1);

namespace justinholtweb\seclude\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property bool $enabled
 * @property int $sortOrder
 * @property string $scopeType
 * @property string|null $settings
 * @property string $uid
 */
class PolicyRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%seclude_policies}}';
    }
}
