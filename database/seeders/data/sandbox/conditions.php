<?php

// Setting conditions for Glieda — the anti-magic bindings of Ironvein, the heat of Phendor, the fear
// of Gloomgrove, and the doctrines of Grimwrath. Original conditions layered atop the standard rules.

return [
    [
        'slug' => 'suppressed',
        'name' => 'Suppressed',
        'summary' => 'An anti-magic band or field has sealed your magic; you cannot cast or channel.',
        'is_private' => false,
        'tags' => ['ironvein', 'anti-magic'],
        'document' => <<<'MD'
# Suppressed

A creature that is **suppressed** — by an Ironvein wristband, a Runeward Sentinel's field, or a spell such as *iron silence* — cannot reach the magic within or around it.

- The creature cannot cast spells, use spell-like abilities, or activate magic items that require attunement.
- Ongoing spells the creature is concentrating on end.
- The creature has disadvantage on ability checks and saving throws that rely on magical senses (such as blindsight granted by magic).

***Removal.*** The condition ends when the band is unlocked, the field is left, or the effect ends. Struggling against a wristband (DC 20 Strength) does not remove it and alerts the guard tower.
MD,
    ],
    [
        'slug' => 'sun-scorched',
        'name' => 'Sun-scorched',
        'summary' => 'The Phendor sun has burned and dried you; you weaken until you find shade and water.',
        'is_private' => false,
        'tags' => ['phendor', 'environment'],
        'document' => <<<'MD'
# Sun-scorched

A creature that spends too long unprotected in the deep Phendor desert becomes **sun-scorched**.

- The creature has disadvantage on Constitution saving throws.
- Its speed is reduced by 10 feet.
- Each hour it remains in the open sun without water, it must repeat a DC 12 Constitution save or gain a level of exhaustion.

***Removal.*** An hour in shade with fresh water (or a *duneglass vial*) ends the condition.
MD,
    ],
    [
        'slug' => 'gloom-touched',
        'name' => 'Gloom-touched',
        'summary' => 'The deep dark of Gloomgrove has crept into you; its silence unnerves and slows you.',
        'is_private' => false,
        'tags' => ['gloomgrove', 'fear'],
        'document' => <<<'MD'
# Gloom-touched

A creature that lingers in the lightless heart of Gloomgrove, or that survives the touch of a Hollow One, becomes **gloom-touched**.

- The creature has disadvantage on saving throws against being frightened.
- When it is in darkness, it must succeed on a DC 12 Wisdom saving throw at the start of each of its turns or be unable to take reactions until the start of its next turn.

***Removal.*** A long rest in bright light, or any effect that removes a curse, ends the condition.
MD,
    ],
    [
        'slug' => 'rune-bound',
        'name' => 'Rune-bound',
        'summary' => 'Glowing iron runes hold you fast; you are restrained until they release or are broken.',
        'is_private' => false,
        'tags' => ['ironvein', 'restraint'],
        'document' => <<<'MD'
# Rune-bound

A creature caught by a Runeward Sentinel's binding rune, or by an Ironvein trap, becomes **rune-bound**.

- The creature is restrained.
- The creature cannot benefit from teleportation or from moving through solid matter while the runes hold.

***Removal.*** The condition ends when the effect that created it ends, or when the runes are broken (they have AC 12 and 10 hit points and are immune to poison and psychic damage).
MD,
    ],
    [
        'slug' => 'oath-sworn',
        'name' => 'Oath-sworn',
        'summary' => 'An Arcmond battle-oath steels you; you fight on where others would falter.',
        'is_private' => false,
        'tags' => ['arcmond', 'boon'],
        'document' => <<<'MD'
# Oath-sworn

A creature under an Arcmond general's oath (such as the *dwarven oath* spell) is **oath-sworn** — a rare beneficial condition.

- The creature has advantage on saving throws against being frightened.
- At the start of each of its turns, it gains 1d6 temporary hit points.

***Removal.*** The condition ends when the oath's duration expires or the creature that swore it is incapacitated.
MD,
    ],
    [
        'slug' => 'faith-marked',
        'name' => 'Faith-marked',
        'summary' => 'Grimwrath\'s church has branded you a suspected mage; doors close and eyes follow.',
        'is_private' => false,
        'tags' => ['grimwrath', 'social'],
        'document' => <<<'MD'
# Faith-marked

A creature the Church of Grimwrath has denounced — rightly or not — as a magic-user is **faith-marked**.

- The creature has disadvantage on Charisma checks made to influence the devout of Grimwrath.
- The church's templars and gargoyles treat the creature as hostile within the city.

***Removal.*** The mark is a social and bureaucratic one: a formal pardon from the Archbishop's office, or leaving Grimwrath's reach, ends its practical effects.
MD,
    ],
    [
        'slug' => 'grove-blessed',
        'name' => 'Grove-blessed',
        'summary' => 'Shendrift\'s forest has taken a liking to you; the wood itself lends a hand.',
        'is_private' => false,
        'tags' => ['shendrift', 'boon'],
        'document' => <<<'MD'
# Grove-blessed

A creature honoured by the elders of Shendrift, or healed by *moonlit mending* beneath the trees, may be **grove-blessed**.

- The creature ignores difficult terrain created by nonmagical plants.
- Once per day, when it fails a saving throw while in a forest, it can choose to succeed instead.

***Removal.*** The blessing fades after the creature next completes a long rest outside a living forest.
MD,
    ],
    [
        'slug' => 'ledgered',
        'name' => 'Ledgered',
        'summary' => 'Your name is on an Ironvein ledger; the fortress is looking for you specifically.',
        'is_private' => false,
        'tags' => ['ironvein', 'social'],
        'document' => <<<'MD'
# Ledgered

A creature whose name has been entered on an Ironvein prisoner ledger — as an escapee, a transfer, or a scheduled arrival — is **ledgered**.

- The fortress's staff and hounds recognise and prioritise the creature.
- Prison transports and Stormhaven's port authority will detain the creature on sight.

***Removal.*** Only Warden Ironfist (or a forged writ good enough to fool Reception) can strike a name from the ledger.
MD,
    ],
    [
        'slug' => 'scholars-focus',
        'name' => "Scholar's Focus",
        'summary' => 'A Phendor discipline sharpens your recall to a razor edge — for a time.',
        'is_private' => false,
        'tags' => ['phendor', 'boon'],
        'document' => <<<'MD'
# Scholar's Focus

A creature that has undergone Phendor's meditative study, or benefits from *scholar's recall*, gains the **scholar's focus** condition.

- The creature has advantage on Intelligence checks that draw on studied knowledge.
- It cannot be forced to forget or be magically confused about the specific subject of its focus.

***Removal.*** The condition ends after 8 hours or when the creature turns its focus to a new subject.
MD,
    ],
    [
        'slug' => 'hollowed',
        'name' => 'Hollowed',
        'summary' => 'A Hollow One has drawn something out of you; your very self feels thinner.',
        'is_private' => false,
        'tags' => ['gloomgrove', 'curse'],
        'document' => <<<'MD'
# Hollowed

A creature drained by a Hollow One of Gloomgrove is **hollowed** — a piece of it carried off into the wood.

- The creature's hit point maximum is reduced by the necrotic damage it took, until it finishes a long rest.
- While hollowed, the creature has disadvantage on death saving throws.

***Removal.*** A long rest restores the hit point maximum; *greater restoration* ends it at once. If a hollowed creature dies, there are stories — only stories — of it rising to walk the grove itself.
MD,
    ],
];
