<?php

declare(strict_types=1);

namespace justinholtweb\seclude\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Seclude's schema.
 *
 * Two of these three tables are disposable. Policies are configuration and live in project config;
 * `{{%seclude_policies}}` is a mirror kept for ordering and fast reads, and refusals are a log.
 *
 * `{{%seclude_assignments}}` is the exception and the only thing here worth backing up: which
 * elements an editor was hand-given is *content*, entered by a person, and there is nowhere else
 * it exists. That is also why it is not in project config — an entry ID means a different entry on
 * every environment, and a deploy that overwrote these rows would silently re-shuffle who can edit
 * what.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%seclude_refusals}}');
        $this->dropTableIfExists('{{%seclude_assignments}}');
        $this->dropTableIfExists('{{%seclude_policies}}');

        return true;
    }

    private function createTables(): void
    {
        $this->createTable('{{%seclude_policies}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'scopeType' => $this->string(32)->notNull(),
            'settings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%seclude_assignments}}', [
            'id' => $this->primaryKey(),
            'policyId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            // Who handed it over. Kept for the audit trail, and nulled rather than cascaded so
            // deleting a departed manager does not erase the record of what they delegated.
            'assignedBy' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%seclude_refusals}}', [
            'id' => $this->primaryKey(),
            'policyId' => $this->integer(),
            'userId' => $this->integer(),
            'elementId' => $this->integer(),
            'ability' => $this->string(16)->notNull(),
            'reason' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        $this->createIndex(null, '{{%seclude_policies}}', ['handle'], true);
        $this->createIndex(null, '{{%seclude_policies}}', ['scopeType', 'enabled']);

        // The unique index is the whole concurrency story for assignments: two admins assigning
        // the same entry to the same editor at once produce one row, not two, and the duplicate
        // insert is caught rather than doubling the grant.
        $this->createIndex(null, '{{%seclude_assignments}}', ['policyId', 'userId', 'elementId'], true);
        $this->createIndex(null, '{{%seclude_assignments}}', ['userId', 'elementId']);
        $this->createIndex(null, '{{%seclude_assignments}}', ['elementId']);

        $this->createIndex(null, '{{%seclude_refusals}}', ['dateCreated']);
        $this->createIndex(null, '{{%seclude_refusals}}', ['userId', 'dateCreated']);
    }

    private function addForeignKeys(): void
    {
        // Assignments cascade on every side. An assignment to a deleted user, or of a deleted
        // element, is not a historical record worth keeping — it is a dangling grant, and the one
        // thing worse than a stale permission row is a stale permission row that starts matching
        // again when an ID is reused.
        $this->addForeignKey(null, '{{%seclude_assignments}}', ['policyId'], '{{%seclude_policies}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%seclude_assignments}}', ['userId'], Table::USERS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%seclude_assignments}}', ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%seclude_assignments}}', ['assignedBy'], Table::USERS, ['id'], 'SET NULL', null);

        // The log outlives what it describes: a refusal is interesting precisely when somebody
        // then deletes the policy or the element and wants to know what happened.
        $this->addForeignKey(null, '{{%seclude_refusals}}', ['policyId'], '{{%seclude_policies}}', ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%seclude_refusals}}', ['userId'], Table::USERS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, '{{%seclude_refusals}}', ['elementId'], Table::ELEMENTS, ['id'], 'SET NULL', null);
    }
}
