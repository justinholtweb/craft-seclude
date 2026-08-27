<?php

declare(strict_types=1);

namespace justinholtweb\seclude\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $policyId
 * @property int $userId
 * @property int $elementId
 * @property int|null $assignedBy
 * @property string $uid
 */
class AssignmentRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%seclude_assignments}}';
    }
}
