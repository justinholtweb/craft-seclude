<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Whether admins can be secluded.
     *
     * Off, and it takes a deliberate act to turn on. A permissions plugin that can lock every
     * admin out of the control panel on a mistyped policy is a plugin nobody should install; this
     * is the guarantee that there is always somebody left who can undo it.
     */
    public bool $secludeAdmins = false;

    /**
     * Assign a new element to the user who created it.
     *
     * On, because the alternative is broken: a secluded user with the create ability saves a new
     * entry, Craft redirects to its edit page, and no grant covers it — so they are refused
     * entry to the thing they just made.
     */
    public bool $adoptCreatedElements = true;

    /** Hide element index sources in which a secluded user has nothing at all. */
    public bool $hideEmptySources = true;

    /**
     * Filter element *selection* modals as well as index listings.
     *
     * On. Off is for the site that uses Seclude to shape editors' workspaces rather than to keep
     * information apart, and does not want a restricted editor unable to link to a page they can
     * see on the front end.
     */
    public bool $filterSelectionModals = true;

    /**
     * Log refusals.
     *
     * Off by default — the interesting refusals are visible in the CP, and a busy site's index
     * requests would write thousands of rows a day for a question nobody is asking.
     */
    public bool $logRefusals = false;

    /** Days of refusal log to keep. */
    public int $logRetentionDays = 30;

    protected function defineRules(): array
    {
        return [
            [['secludeAdmins', 'adoptCreatedElements', 'hideEmptySources', 'filterSelectionModals', 'logRefusals'], 'boolean'],
            [['logRetentionDays'], 'integer', 'min' => 1, 'max' => 3650],
        ];
    }
}
