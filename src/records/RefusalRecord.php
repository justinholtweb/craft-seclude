<?php

declare(strict_types=1);

namespace justinholtweb\seclude\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $policyId
 * @property int|null $userId
 * @property int|null $elementId
 * @property string $ability
 * @property string|null $reason
 * @property string $uid
 */
class RefusalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%seclude_refusals}}';
    }
}
