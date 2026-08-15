<?php

// Magic items of Glieda. The first tag names the item type and one tag names the rarity, which is how
// the reader shows an item's type and rarity at a glance.

return [
    [
        'slug' => 'anti-magic-manacles',
        'name' => 'Anti-Magic Manacles',
        'summary' => 'Rune-etched Ironvein restraints that seal a prisoner\'s magic as surely as their wrists.',
        'is_private' => false,
        'tags' => ['wondrous', 'rare'],
        'document' => <<<'MD'
# Anti-Magic Manacles
*Wondrous item, rare*

Forged by the smiths of the Ironvein Fortress, these iron manacles are covered in tiny suppression runes. A creature wearing them cannot cast spells, and any magic item it wears or carries that requires attunement functions as a mundane object.

The manacles have AC 19 and 15 hit points. They can be removed by the creature that locked them, or by a successful DC 20 Dexterity check made with thieves' tools by another creature.
MD,
    ],
    [
        'slug' => 'wardens-signet',
        'name' => "Warden's Signet",
        'summary' => 'Warden Gorlak Ironfist\'s ring of office, which opens every lock in Iron Hell.',
        'is_private' => false,
        'tags' => ['ring', 'rare'],
        'document' => <<<'MD'
# Warden's Signet
*Ring, rare (requires attunement)*

This heavy iron ring bears the seal of the Ironvein Fortress. While attuned, you can use an action to unlock any nonmagical lock within 5 feet, and any door or restraint enchanted by the fortress's smiths opens at your touch. The Runeward Sentinels of Ironvein will not attack the ring's bearer unless commanded to by name.
MD,
    ],
    [
        'slug' => 'grovewood-bow',
        'name' => 'Grovewood Bow',
        'summary' => 'A living Shendrift longbow that never needs restringing and looses true through the trees.',
        'is_private' => false,
        'tags' => ['weapon', 'uncommon'],
        'document' => <<<'MD'
# Grovewood Bow
*Weapon (longbow), uncommon (requires attunement)*

Grown rather than carved by the artisans of Shendrift, this longbow gives a +1 bonus to attack and damage rolls. Its arrows ignore the penalties for half and three-quarters cover, curving gently around branch and pillar to find their mark.
MD,
    ],
    [
        'slug' => 'arcmond-warplate',
        'name' => 'Arcmond Warplate',
        'summary' => 'Dwarven plate from the forges of Arcmond, proof against the sea and the blade.',
        'is_private' => false,
        'tags' => ['armor', 'rare'],
        'document' => <<<'MD'
# Arcmond Warplate
*Armor (plate), rare (requires attunement)*

This masterwork plate from the Arcmond shipyards grants a +1 bonus to AC. It never rusts or corrodes, and while you wear it you can breathe normally and move without penalty underwater — a gift the dwarven marines value above almost any other.
MD,
    ],
    [
        'slug' => 'scholars-lens',
        'name' => "Scholar's Lens",
        'summary' => 'A Phendor reading-glass that renders any script legible — even the ciphered and the ancient.',
        'is_private' => false,
        'tags' => ['wondrous', 'uncommon'],
        'document' => <<<'MD'
# Scholar's Lens
*Wondrous item, uncommon*

Ground in the workshops of the University of Oxdohr, this brass-rimmed lens lets you read any language, decipher simple codes, and gain advantage on Intelligence (Investigation) checks made to study documents. Text viewed through it glows faintly where it has been magically altered or forged.
MD,
    ],
    [
        'slug' => 'lantern-of-judgement',
        'name' => 'Lantern of Judgement',
        'summary' => 'A Grimwrath censer-lantern whose light reveals illusions and unsettles the arcane.',
        'is_private' => false,
        'tags' => ['wondrous', 'uncommon'],
        'document' => <<<'MD'
# Lantern of Judgement
*Wondrous item, uncommon (requires attunement)*

Blessed in St Ulrich's Cathedral, this lantern sheds bright light in a 30-foot radius. Within that light, illusions of your spell level or lower are revealed, and any creature concentrating on a spell has disadvantage on the saving throw to maintain it. Devout Grimwrath templars carry these into every place they suspect of witchcraft.
MD,
    ],
    [
        'slug' => 'gloomstep-cloak',
        'name' => 'Gloomstep Cloak',
        'summary' => 'A goblin-woven Gloomgrove cloak that drinks the light and hides its wearer among the trees.',
        'is_private' => false,
        'tags' => ['wondrous', 'uncommon'],
        'document' => <<<'MD'
# Gloomstep Cloak
*Wondrous item, uncommon (requires attunement)*

Woven from Gloomgrove shadow-moss, this ragged cloak grants advantage on Dexterity (Stealth) checks made in dim light or darkness. While you are motionless in such conditions, you are invisible until you move or take an action.
MD,
    ],
    [
        'slug' => 'duneglass-vial',
        'name' => 'Duneglass Vial',
        'summary' => 'A Phendor draught of desert-glass water that wards against heat and thirst for a day.',
        'is_private' => false,
        'tags' => ['potion', 'uncommon'],
        'document' => <<<'MD'
# Duneglass Vial
*Potion, uncommon*

When you drink this shimmering draught, you ignore the effects of extreme heat and require no food or water for 24 hours, and you gain resistance to fire damage for 1 hour. Phendor's caravans will not cross the deep desert without one for every traveller.
MD,
    ],
    [
        'slug' => 'councillors-brooch',
        'name' => "Councillor's Brooch",
        'summary' => 'An Ankmier badge of office that turns a hostile crowd civil — for a moment.',
        'is_private' => false,
        'tags' => ['wondrous', 'uncommon'],
        'document' => <<<'MD'
# Councillor's Brooch
*Wondrous item, uncommon (requires attunement)*

Worn by the councillors of Ankmier, this enamelled brooch lets you cast *calm emotions* (save DC 13) once per day without expending a spell slot. While you wear it, you also have advantage on Charisma (Persuasion) checks made to address a crowd.
MD,
    ],
    [
        'slug' => 'ironvein-skeleton-key',
        'name' => 'Ironvein Skeleton Key',
        'summary' => 'A key that should not exist — said to open any door in the inescapable fortress. Deeply illegal to possess.',
        'is_private' => true,
        'tags' => ['wondrous', 'legendary'],
        'document' => <<<'MD'
# Ironvein Skeleton Key
*Wondrous item, legendary (requires attunement)*

A thin key of dull grey metal, warm to the touch. As an action, you can touch it to any lock, restraint, or door — however mundane or enchanted — and it opens, once. The key then goes cold for 1 hour. It even opens the Anti-Magic Manacles and the doors sealed by the Warden's Signet.

No one at the Ironvein Fortress will admit such a thing was ever made. Its very existence is the fortress's worst-kept and most-denied secret.
MD,
    ],
];
