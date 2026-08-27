<?php

declare(strict_types=1);

namespace justinholtweb\seclude\models;

use craft\base\Model;

/**
 * The set of abilities one policy permits.
 *
 * **Everything defaults to off.** A policy built from a partial array — a hand-written project
 * config, a migration, a seed script — grants only what it names. The alternative reads better in
 * the constructor and is indefensible in a permissions plugin: an abilities array that forgot to
 * mention `save` would silently permit saving.
 *
 * {@see self::suggested()} carries the sensible starting point instead, and the control panel uses
 * it to pre-tick the boxes on a new policy. So the friendly default is still there — it is just in
 * the place where a human is looking at it, rather than in the place where nobody is.
 */
class Abilities extends Model
{
    public bool $view = false;
    public bool $save = false;
    public bool $create = false;
    public bool $delete = false;
    public bool $duplicate = false;
    public bool $propose = false;

    /**
     * What a new policy starts with in the control panel.
     *
     * View, edit and propose: the arrangement somebody has in mind when they say "let this editor
     * work on these entries". Create, delete and duplicate stay off, because those three are how a
     * restricted user gets *around* a restriction — by making a sibling, or by deleting the thing
     * they were supposed to be editing carefully.
     */
    public static function suggested(): self
    {
        return new self(['view' => true, 'save' => true, 'propose' => true]);
    }

    public function allows(string $ability): bool
    {
        return match ($ability) {
            Ability::VIEW => $this->view,
            Ability::SAVE => $this->save,
            Ability::CREATE => $this->create,
            Ability::DELETE => $this->delete,
            Ability::DUPLICATE => $this->duplicate,
            Ability::PROPOSE => $this->propose,
            default => false,
        };
    }

    /**
     * Repair a set that grants something it cannot support.
     *
     * Called on save rather than on read, so what the admin sees stored is what they will get.
     * Silently dropping "save without view" at read time instead would leave a checkbox ticked in
     * the CP that does nothing, which is the sort of thing that costs somebody an afternoon.
     */
    public function normalize(): void
    {
        if ($this->view) {
            return;
        }

        $this->save = false;
        $this->delete = false;
        $this->duplicate = false;
        $this->propose = false;
    }

    /** @return string[] */
    public function granted(): array
    {
        return array_values(array_filter(Ability::all(), fn(string $a) => $this->allows($a)));
    }

    public function isEmpty(): bool
    {
        return $this->granted() === [];
    }

    public function getConfig(): array
    {
        return [
            'view' => $this->view,
            'save' => $this->save,
            'create' => $this->create,
            'delete' => $this->delete,
            'duplicate' => $this->duplicate,
            'propose' => $this->propose,
        ];
    }
}
