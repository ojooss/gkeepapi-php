<?php

declare(strict_types=1);

namespace Gkeepapi\Node;

/**
 * Represents the settings associated with a {@see TopLevelNode}.
 *
 * Port of gkeepapi/node.py `NodeSettings` (MIT, (c) Kai Zhong / kiwiz).
 */
class NodeSettings extends Element
{
    private NewListItemPlacementValue $newListitemPlacement = NewListItemPlacementValue::Bottom;
    private GraveyardStateValue $graveyardState = GraveyardStateValue::Collapsed;
    private CheckedListItemsPolicyValue $checkedListitemsPolicy = CheckedListItemsPolicyValue::Graveyard;

    /** @inheritDoc */
    public function save(bool $clean = true): array
    {
        $ret = parent::save($clean);
        $ret['newListItemPlacement'] = $this->newListitemPlacement->value;
        $ret['graveyardState'] = $this->graveyardState->value;
        $ret['checkedListItemsPolicy'] = $this->checkedListitemsPolicy->value;

        return $ret;
    }

    /** Get the default location to insert new listitems. */
    public function getNewListitemPlacement(): NewListItemPlacementValue
    {
        return $this->newListitemPlacement;
    }

    /** Set the default location to insert new listitems. */
    public function setNewListitemPlacement(NewListItemPlacementValue $value): void
    {
        $this->newListitemPlacement = $value;
        $this->dirty = true;
    }

    /** Get the visibility state for the list graveyard. */
    public function getGraveyardState(): GraveyardStateValue
    {
        return $this->graveyardState;
    }

    /** Set the visibility state for the list graveyard. */
    public function setGraveyardState(GraveyardStateValue $value): void
    {
        $this->graveyardState = $value;
        $this->dirty = true;
    }

    /** Get the policy for checked listitems. */
    public function getCheckedListitemsPolicy(): CheckedListItemsPolicyValue
    {
        return $this->checkedListitemsPolicy;
    }

    /** Set the policy for checked listitems. */
    public function setCheckedListitemsPolicy(CheckedListItemsPolicyValue $value): void
    {
        $this->checkedListitemsPolicy = $value;
        $this->dirty = true;
    }

    /** @inheritDoc */
    protected function doLoad(array $raw): void
    {
        parent::doLoad($raw);
        $this->newListitemPlacement = NewListItemPlacementValue::from(
            self::str(self::req($raw, 'newListItemPlacement')),
        );
        $this->graveyardState = GraveyardStateValue::from(self::str(self::req($raw, 'graveyardState')));
        $this->checkedListitemsPolicy = CheckedListItemsPolicyValue::from(
            self::str(self::req($raw, 'checkedListItemsPolicy')),
        );
    }
}
