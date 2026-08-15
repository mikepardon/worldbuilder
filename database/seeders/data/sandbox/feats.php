<?php

// Feats rooted in Aeria's cities and callings — the marines of Arcmond, the zealots of Grimwrath, the
// scholars of Phendor, the wardens of Shendrift, and the survivors of the Ironvein Fortress. Original
// feats layered atop the standard rules.

return [
    [
        'slug' => 'arcmond-marine',
        'name' => 'Arcmond Marine',
        'summary' => 'You trained in the shipyards and academies of Arcmond, at home on a heaving deck.',
        'is_private' => false,
        'tags' => ['arcmond', 'martial'],
        'document' => <<<'MD'
# Arcmond Marine

Schooled by the dwarven generals of Arcmond, you fight as well at sea as on stone. You gain the following benefits:

- Increase your Strength or Constitution by 1, to a maximum of 20.
- You ignore the penalties for fighting on unstable footing, and you can't be knocked prone unless you choose to be while on a ship.
- You gain proficiency with martial weapons and with water vehicles.
MD,
    ],
    [
        'slug' => 'grimwrath-zealot',
        'name' => 'Grimwrath Zealot',
        'summary' => 'You carry the martial faith of Grimwrath, and your fervour bites hardest at the arcane.',
        'is_private' => false,
        'tags' => ['grimwrath', 'faith'],
        'document' => <<<'MD'
# Grimwrath Zealot

Trained by the church-militant of Grimwrath, you turn your devotion against those who wield magic. You gain the following benefits:

- Increase your Wisdom or Charisma by 1, to a maximum of 20.
- Once per turn, when you hit a creature that is concentrating on a spell, you deal an extra 1d6 radiant damage.
- You have advantage on saving throws against spells cast by creatures you can see.
MD,
    ],
    [
        'slug' => 'phendor-scholar',
        'name' => 'Phendor Scholar',
        'summary' => 'You studied at the University of Oxdohr, and knowledge answers when you call.',
        'is_private' => false,
        'tags' => ['phendor', 'lore'],
        'document' => <<<'MD'
# Phendor Scholar

Years in the Great Library have made you a formidable mind. You gain the following benefits:

- Increase your Intelligence by 1, to a maximum of 20.
- You gain proficiency in two of the following skills: Arcana, History, Nature, or Religion. Your proficiency bonus is doubled for any check you make with one of them.
- When you fail an Intelligence check to recall lore, you can spend 10 minutes and a library or notes to treat it as a success.
MD,
    ],
    [
        'slug' => 'shendrift-warden',
        'name' => 'Shendrift Warden',
        'summary' => 'You keep the ways of Shendrift, moving through the forest as though it were your own hall.',
        'is_private' => false,
        'tags' => ['shendrift', 'nature'],
        'document' => <<<'MD'
# Shendrift Warden

The elders of Shendrift taught you to walk with the wood, not against it. You gain the following benefits:

- Increase your Dexterity or Wisdom by 1, to a maximum of 20.
- Difficult terrain made of nonmagical plants costs you no extra movement.
- You can attempt to hide even when only lightly obscured by foliage, and you have advantage on Stealth checks made in a forest.
MD,
    ],
    [
        'slug' => 'gloomgrove-trapper',
        'name' => 'Gloomgrove Trapper',
        'summary' => 'You learned the goblin arts of the shadow-wood: snares, ambush, and vanishing.',
        'is_private' => false,
        'tags' => ['gloomgrove', 'stealth'],
        'document' => <<<'MD'
# Gloomgrove Trapper

You know Gloomgrove's cruel little tricks. You gain the following benefits:

- Increase your Dexterity by 1, to a maximum of 20.
- You can set a snare in 1 minute with a goblin snare kit; a creature entering the space must succeed on a Dexterity saving throw (DC = 8 + your proficiency bonus + your Dexterity modifier) or be restrained.
- Once per turn, when you attack from hiding, you deal an extra 1d6 damage on a hit.
MD,
    ],
    [
        'slug' => 'ironvein-survivor',
        'name' => 'Ironvein Survivor',
        'summary' => 'You did time in Iron Hell and walked out. Little frightens you now, and less holds you.',
        'is_private' => false,
        'tags' => ['ironvein', 'grit'],
        'document' => <<<'MD'
# Ironvein Survivor

You endured the Ironvein Fortress — its wristbands, its hounds, its solitary dark — and came out harder. You gain the following benefits:

- Increase your Constitution or Wisdom by 1, to a maximum of 20.
- You have advantage on ability checks made to escape restraints and on saving throws against being frightened.
- You are proficient with thieves' tools, and you can attempt to pick a lock or slip a restraint as a bonus action.
MD,
    ],
    [
        'slug' => 'ankmier-diplomat',
        'name' => 'Ankmier Diplomat',
        'summary' => 'You learned to work a crowd in the most cosmopolitan city on the continent.',
        'is_private' => false,
        'tags' => ['ankmier', 'social'],
        'document' => <<<'MD'
# Ankmier Diplomat

Raised amid Ankmier's many peoples, you speak to all of them. You gain the following benefits:

- Increase your Charisma by 1, to a maximum of 20.
- You gain proficiency in Persuasion and Insight; if you are already proficient, your proficiency bonus is doubled for those checks.
- You can speak, read, and write two additional languages of your choice.
MD,
    ],
    [
        'slug' => 'desert-strider',
        'name' => 'Desert Strider',
        'summary' => 'The deep dunes of Phendor hold no terror for you; you cross them where others perish.',
        'is_private' => false,
        'tags' => ['phendor', 'survival'],
        'document' => <<<'MD'
# Desert Strider

You are a child of the Phendor sands. You gain the following benefits:

- Increase your Constitution or Wisdom by 1, to a maximum of 20.
- You are immune to exhaustion caused by extreme heat, and you can find water and safe passage in the desert where others cannot.
- Loose sand and dunes are not difficult terrain for you, and you leave no trail unless you choose to.
MD,
    ],
    [
        'slug' => 'rune-warded',
        'name' => 'Rune-Warded',
        'summary' => 'You bear the protective runework of Grimwrath or Ironvein, a shield against hostile magic.',
        'is_private' => false,
        'tags' => ['grimwrath', 'ironvein', 'defence'],
        'document' => <<<'MD'
# Rune-Warded

Rune-work etched into your gear — or your skin — turns magic aside. You gain the following benefits:

- Increase your Constitution or Intelligence by 1, to a maximum of 20.
- You have advantage on saving throws against spells of 3rd level or lower.
- When a creature within 10 feet of you casts a spell, you know its school of magic and whether it targets you.
MD,
    ],
    [
        'slug' => 'council-trained',
        'name' => 'Council-Trained',
        'summary' => 'You served in an Aerian ruling body and learned to command a room without raising your voice.',
        'is_private' => false,
        'tags' => ['ankmier', 'leadership'],
        'document' => <<<'MD'
# Council-Trained

Whether on the Ankmier council or in a Niswick noble house, you learned to lead. You gain the following benefits:

- Increase your Wisdom or Charisma by 1, to a maximum of 20.
- As a bonus action, you can grant one ally who can see or hear you advantage on their next ability check, attack, or saving throw made before the end of your next turn.
- You can use this feat's bonus action a number of times equal to your proficiency bonus, regaining all uses on a long rest.
MD,
    ],
];
