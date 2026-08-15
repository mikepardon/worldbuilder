<?php

namespace App\Support;

/** Starter Markdown documents for hand-written compendium entries, so a blank entry isn't truly blank. */
class CompendiumTemplates
{
    /** A ready-to-fill Markdown skeleton for a new entry of the given type. */
    public static function for(string $type, string $name): string
    {
        return match ($type) {
            'spell' => self::spell($name),
            'magicitem' => self::magicItem($name),
            'weapon' => self::weapon($name),
            'armor' => self::armor($name),
            'condition' => "# {$name}\n\nWhile affected by **{$name}**, a creature:\n\n- \n- \n",
            'race' => self::race($name),
            'feat' => "# {$name}\n\n*Prerequisite: —*\n\nYou have honed a talent. You gain the following benefits:\n\n- \n- \n",
            default => "# {$name}\n\n",
        };
    }

    protected static function spell(string $name): string
    {
        return <<<MD
        # {$name}

        *Nth-level school (ritual)*

        - **Casting Time:** 1 action
        - **Range:** 60 feet
        - **Components:** V, S, M (a pinch of description)
        - **Duration:** Instantaneous

        Describe what the spell does here.

        ***At Higher Levels.*** When you cast this spell using a spell slot of Nth level or higher, the effect increases.
        MD;
    }

    protected static function magicItem(string $name): string
    {
        return <<<MD
        # {$name}

        *Wondrous item, rarity (requires attunement)*

        Describe the item, its history, and what it does.

        - **Charges:**
        - **Activation:**
        MD;
    }

    protected static function weapon(string $name): string
    {
        return <<<MD
        # {$name}

        *Weapon (martial melee)*

        - **Damage:** 1d8 slashing
        - **Properties:** versatile (1d10)
        - **Weight:** 3 lb.

        Describe any special properties.
        MD;
    }

    protected static function armor(string $name): string
    {
        return <<<MD
        # {$name}

        *Armor (medium)*

        - **Armor Class:** 14 + Dex modifier (max 2)
        - **Strength:** —
        - **Stealth:** —
        - **Weight:** 20 lb.

        Describe any special properties.
        MD;
    }

    protected static function race(string $name): string
    {
        return <<<MD
        # {$name}

        Describe the people, their look and their culture.

        ***Ability Score Increase.***
        ***Age.***
        ***Size.***
        ***Speed.*** 30 feet.
        MD;
    }
}
