<?php

// The peoples of Glieda, written as playable heritages tied to Aeria's regions. Original traits layered
// atop the standard rules; the reader shows each entry's size, speed and ability line from its fields.

return [
    [
        'slug' => 'aerian-human',
        'name' => 'Aerian Human',
        'summary' => 'The adaptable folk of Niswick and Ankmier, at home in council hall and marketplace alike.',
        'is_private' => false,
        'tags' => ['human', 'ankmier', 'niswick'],
        'document' => <<<'MD'
# Aerian Human
*Medium humanoid · Speed 30 ft. · +1 to two ability scores of your choice*

The humans of Aeria are its connective tissue — the merchants of Ankmier, the nobles of Niswick, the devout of Grimwrath.

***Versatile.*** You gain proficiency in one skill and one tool of your choice.

***Well-Connected.*** In any Aerian city, you can usually find a contact — a distant cousin, an old debt, a friend of a friend — willing to hear you out.
MD,
    ],
    [
        'slug' => 'arcmond-dwarf',
        'name' => 'Arcmond Dwarf',
        'summary' => 'Shipwright-soldiers of the eastern waterfront, as sturdy as the hulls they build.',
        'is_private' => false,
        'tags' => ['dwarf', 'arcmond'],
        'document' => <<<'MD'
# Arcmond Dwarf
*Medium humanoid · Speed 25 ft. (never reduced by heavy armour) · +2 Constitution, +1 Strength*

The dwarves of Arcmond are born to the forge and the shipyard, and raised on discipline.

***Darkvision.*** You can see in dim light within 60 feet as if it were bright light.

***Sea-Forged.*** You have proficiency with smith's tools and shipwright's tools, and advantage on saving throws against poison.

***Steady Footing.*** You have advantage on checks and saves to avoid being knocked prone on a pitching deck or unstable ground.
MD,
    ],
    [
        'slug' => 'shendrift-elf',
        'name' => 'Shendrift Elf',
        'summary' => 'Forest-kin of the ancient elven city, who live alongside magic where others fear it.',
        'is_private' => false,
        'tags' => ['elf', 'shendrift'],
        'document' => <<<'MD'
# Shendrift Elf
*Medium humanoid · Speed 35 ft. · +2 Dexterity, +1 Wisdom*

The elves of Shendrift are woven into their forest, unhurried and long-memoried.

***Darkvision & Fey Ancestry.*** You see in dim light within 60 feet, and you have advantage on saves against being charmed; magic can't put you to sleep.

***Grovewise.*** You ignore difficult terrain made of nonmagical plants, and you have proficiency in the Nature skill.

***Trance.*** You do not sleep; you meditate 4 hours for the benefit of a long rest.
MD,
    ],
    [
        'slug' => 'phendor-gnome',
        'name' => 'Phendor Gnome',
        'summary' => 'Restless inventor-scholars of the desert academies, forever chasing the next idea.',
        'is_private' => false,
        'tags' => ['gnome', 'phendor'],
        'document' => <<<'MD'
# Phendor Gnome
*Small humanoid · Speed 25 ft. · +2 Intelligence, +1 Dexterity*

Gnomes make up much of Phendor, and much of what Phendor knows.

***Darkvision.*** You can see in dim light within 60 feet as if it were bright light.

***Gnome Cunning.*** You have advantage on Intelligence, Wisdom, and Charisma saving throws against magic.

***Tinker's Insight.*** You have proficiency with one artisan's or scholar's tool set, and you can identify the purpose of a simple mechanism or device after a minute of study.
MD,
    ],
    [
        'slug' => 'phendor-halfling',
        'name' => 'Phendor Halfling',
        'summary' => 'Desert-hardy halflings who keep the caravans of Phendor moving across the dunes.',
        'is_private' => false,
        'tags' => ['halfling', 'phendor'],
        'document' => <<<'MD'
# Phendor Halfling
*Small humanoid · Speed 25 ft. · +2 Dexterity, +1 Constitution*

The halflings of Phendor are the guides, cooks and quiet fixers of every desert caravan.

***Lucky.*** When you roll a 1 on an attack roll, ability check, or saving throw, you can reroll and must use the new roll.

***Brave.*** You have advantage on saving throws against being frightened.

***Sandborn.*** You suffer no penalty from the first level of exhaustion caused by desert heat.
MD,
    ],
    [
        'slug' => 'gloomgrove-goblin',
        'name' => 'Gloomgrove Goblin',
        'summary' => 'The misunderstood folk of the shadow-wood — quick, cunning, and hard to pin down.',
        'is_private' => false,
        'tags' => ['goblin', 'gloomgrove'],
        'document' => <<<'MD'
# Gloomgrove Goblin
*Small humanoid · Speed 30 ft. · +2 Dexterity, +1 Constitution*

The goblins of Gloomgrove are mildly civilised, rarely educated, and consistently underestimated.

***Darkvision.*** You can see in dim light within 60 feet as if it were bright light.

***Nimble Escape.*** You can take the Disengage or Hide action as a bonus action on each of your turns.

***Wood-Wise.*** You have proficiency in Stealth, and advantage on Survival checks to navigate or hide in a forest.
MD,
    ],
    [
        'slug' => 'peak-goliath',
        'name' => 'Peak Goliath',
        'summary' => 'Towering, gentle giants of Aeria\'s heights — like Stout of the Ankmier council.',
        'is_private' => false,
        'tags' => ['goliath', 'ankmier'],
        'document' => <<<'MD'
# Peak Goliath
*Medium humanoid · Speed 30 ft. · +2 Strength, +1 Constitution*

Goliaths come down from Aeria's mountains slow to anger and easy to like.

***Powerful Build.*** You count as one size larger for carrying capacity and for lifting, pushing, or dragging.

***Mountain Born.*** You are acclimatised to high altitude and cold, and you have resistance to cold damage.

***Stone's Endurance.*** When you take damage, you can use your reaction to roll a d12 and reduce it by that amount plus your Constitution modifier. Once used, you must finish a short or long rest to do so again.
MD,
    ],
    [
        'slug' => 'ankmier-half-elf',
        'name' => 'Ankmier Half-elf',
        'summary' => 'Children of two peoples, thriving in the one city that holds all of them at once.',
        'is_private' => false,
        'tags' => ['half-elf', 'ankmier'],
        'document' => <<<'MD'
# Ankmier Half-elf
*Medium humanoid · Speed 30 ft. · +2 Charisma, +1 to two other scores*

Nowhere are the children of elf and human more at home than in Ankmier, the city of all peoples.

***Darkvision & Fey Ancestry.*** You see in dim light within 60 feet, and you have advantage on saves against being charmed; magic can't put you to sleep.

***Two Worlds.*** You gain proficiency in two skills of your choice — the inheritance of a life lived between cultures.
MD,
    ],
    [
        'slug' => 'ironvein-half-orc',
        'name' => 'Half-orc',
        'summary' => 'Strong and enduring, and — more than their share — found within the walls of Ironvein.',
        'is_private' => false,
        'tags' => ['half-orc', 'ironvein'],
        'document' => <<<'MD'
# Half-orc
*Medium humanoid · Speed 30 ft. · +2 Strength, +1 Constitution*

Half-orcs are found across Aeria as soldiers, labourers and workshop-masters — like Ironvein's own Master Jervis.

***Darkvision.*** You can see in dim light within 60 feet as if it were bright light.

***Relentless Endurance.*** When you are reduced to 0 hit points but not killed outright, you can drop to 1 hit point instead. Once used, you must finish a long rest to do so again.

***Savage Attacks.*** When you score a critical hit with a melee weapon, roll one of the weapon's damage dice one additional time.
MD,
    ],
    [
        'slug' => 'aerian-orc',
        'name' => 'Aerian Orc',
        'summary' => 'Fierce and feared across the continent, and slowly finding a place among its cities.',
        'is_private' => false,
        'tags' => ['orc', 'aeria'],
        'document' => <<<'MD'
# Aerian Orc
*Medium humanoid · Speed 30 ft. · +2 Strength, +1 Constitution*

Orcs walk Aeria as warriors and wanderers; a few, like Gloomgrove's neighbours, are beginning to find a foothold in its cities.

***Darkvision.*** You can see in dim light within 60 feet as if it were bright light.

***Aggressive.*** As a bonus action, you can move up to your speed toward an enemy you can see or hear.

***Powerful Build.*** You count as one size larger for carrying capacity and for lifting, pushing, or dragging.
MD,
    ],
];
