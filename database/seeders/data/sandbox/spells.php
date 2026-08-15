<?php

// Spells written for Glieda — the anti-magic wards of Ironvein and Grimwrath, the grove-magic of
// Shendrift, the desert lore of Phendor. The first tag names the school and one tag names the level,
// which is how the reader shows a spell's Level and School at a glance.

return [
    [
        'slug' => 'ember-bolt',
        'name' => 'Ember Bolt',
        'summary' => 'A mote of forge-fire hurled at a foe — a favourite first spell of Arcmond\'s battle-mages.',
        'is_private' => false,
        'tags' => ['evocation', 'cantrip', 'fire', 'ranged'],
        'document' => <<<'MD'
# Ember Bolt
*Evocation cantrip*

- **Casting Time:** 1 action
- **Range:** 120 feet
- **Components:** V, S
- **Duration:** Instantaneous

You hurl a mote of forge-fire at a creature or object within range. Make a ranged spell attack against the target. On a hit, the target takes 1d10 fire damage. A flammable object hit by this spell ignites if it isn't being worn or carried.

***At Higher Levels.*** The damage increases by 1d10 when you reach 5th level (2d10), 11th level (3d10), and 17th level (4d10).
MD,
    ],
    [
        'slug' => 'wardbreaker',
        'name' => 'Wardbreaker',
        'summary' => 'A surge of raw counter-magic that unravels wards — banned in Grimwrath, prized everywhere else.',
        'is_private' => false,
        'tags' => ['abjuration', '3rd-level', 'dispel'],
        'document' => <<<'MD'
# Wardbreaker
*3rd-level abjuration*

- **Casting Time:** 1 action
- **Range:** 120 feet
- **Components:** V, S
- **Duration:** Instantaneous

Choose one creature, object, or magical effect within range. Any spell of 3rd level or lower on the target ends. For each spell of 4th level or higher on the target, make an ability check using your spellcasting ability (DC 10 + the spell's level). On a success, that spell ends.

***At Higher Levels.*** When you cast this spell using a slot of 4th level or higher, you automatically end a spell on the target if the spell's level is equal to or less than the level of the slot you used.
MD,
    ],
    [
        'slug' => 'iron-silence',
        'name' => 'Iron Silence',
        'summary' => 'The suppression woven into the walls of the Ironvein Fortress, drawn up as a portable field.',
        'is_private' => false,
        'tags' => ['abjuration', '5th-level', 'anti-magic'],
        'document' => <<<'MD'
# Iron Silence
*5th-level abjuration*

- **Casting Time:** 1 action
- **Range:** Self (10-foot-radius sphere)
- **Components:** V, S, M (a shard of rune-etched iron from Ironvein)
- **Duration:** Concentration, up to 10 minutes

A 10-foot-radius invisible sphere of anti-magic surrounds you and moves with you. Within it, spells of 3rd level or lower cannot be cast, and ongoing spell effects of that level are suppressed. Magic items that require attunement function as mundane objects while inside the sphere.
MD,
    ],
    [
        'slug' => 'grove-step',
        'name' => 'Grove Step',
        'summary' => 'Shendrift travel-magic: step into one tree and out of another.',
        'is_private' => false,
        'tags' => ['conjuration', '4th-level', 'teleportation'],
        'document' => <<<'MD'
# Grove Step
*4th-level conjuration*

- **Casting Time:** 1 action
- **Range:** Self
- **Components:** V, S
- **Duration:** Instantaneous

You enter a living tree within 5 feet of you and emerge from a second living tree of your choice within 500 feet, appearing in an unoccupied space within 5 feet of it. Both trees must be Large or bigger. If you are not within 5 feet of a suitable tree, the spell fails and is wasted.
MD,
    ],
    [
        'slug' => 'scholars-recall',
        'name' => "Scholar's Recall",
        'summary' => 'A Phendor divination that lets a mind hold and retrieve a page of knowledge with perfect clarity.',
        'is_private' => false,
        'tags' => ['divination', '2nd-level', 'knowledge'],
        'document' => <<<'MD'
# Scholar's Recall
*2nd-level divination (ritual)*

- **Casting Time:** 1 minute
- **Range:** Touch
- **Components:** V, S, M (a page of the Great Library)
- **Duration:** 8 hours

You touch a willing creature and fix a single page of written knowledge in its memory. For the duration, the creature can recall the page word-for-word and has advantage on any Intelligence check that draws directly upon it.
MD,
    ],
    [
        'slug' => 'sandskin',
        'name' => 'Sandskin',
        'summary' => 'A Phendor transmutation that turns the caster\'s skin to shifting sand against the desert sun and blade alike.',
        'is_private' => false,
        'tags' => ['transmutation', '2nd-level', 'protection'],
        'document' => <<<'MD'
# Sandskin
*2nd-level transmutation*

- **Casting Time:** 1 action
- **Range:** Self
- **Components:** V, S, M (a pinch of desert sand)
- **Duration:** Concentration, up to 1 hour

Your skin takes on the shifting texture of fine sand. You gain resistance to fire damage, ignore the effects of extreme heat, and any creature that hits you with a melee attack takes 1d4 slashing damage as the abrasive sand tears at it.
MD,
    ],
    [
        'slug' => 'faithful-flame',
        'name' => 'Faithful Flame',
        'summary' => 'A Grimwrath evocation that burns brighter against the corrupt and the arcane.',
        'is_private' => false,
        'tags' => ['evocation', '1st-level', 'radiant'],
        'document' => <<<'MD'
# Faithful Flame
*1st-level evocation*

- **Casting Time:** 1 action
- **Range:** 60 feet
- **Components:** V, S
- **Duration:** Instantaneous

You call down a lance of pure white flame on a creature within range. Make a ranged spell attack. On a hit, the target takes 2d8 radiant damage. If the target is concentrating on a spell, it takes an extra 1d8 radiant damage.

***At Higher Levels.*** When you cast this spell using a slot of 2nd level or higher, the initial damage increases by 1d8 for each slot level above 1st.
MD,
    ],
    [
        'slug' => 'gloom-veil',
        'name' => 'Gloom Veil',
        'summary' => 'A Gloomgrove illusion that wraps the caster and their allies in the wood\'s own shadow.',
        'is_private' => false,
        'tags' => ['illusion', '3rd-level', 'stealth'],
        'document' => <<<'MD'
# Gloom Veil
*3rd-level illusion*

- **Casting Time:** 1 action
- **Range:** Self (30-foot radius)
- **Components:** V, S
- **Duration:** Concentration, up to 10 minutes

Shadow gathers around you in a 30-foot radius that moves with you. You and any creatures of your choice within it are heavily obscured to anyone outside it, and have advantage on Dexterity (Stealth) checks. Bright light within the area is reduced to dim light.
MD,
    ],
    [
        'slug' => 'dwarven-oath',
        'name' => 'Dwarven Oath',
        'summary' => 'An Arcmond battle-blessing that steels a company for the fight ahead.',
        'is_private' => false,
        'tags' => ['enchantment', '3rd-level', 'buff'],
        'document' => <<<'MD'
# Dwarven Oath
*3rd-level enchantment*

- **Casting Time:** 1 action
- **Range:** 30 feet
- **Components:** V, S
- **Duration:** Concentration, up to 1 minute

You speak a general's oath over up to six creatures of your choice within range. For the duration, each affected creature has advantage on saving throws against being frightened and gains 1d6 temporary hit points at the start of each of its turns.
MD,
    ],
    [
        'slug' => 'moonlit-mending',
        'name' => 'Moonlit Mending',
        'summary' => 'Shendrift restoration-magic drawn from the light of the two moons.',
        'is_private' => false,
        'tags' => ['abjuration', '2nd-level', 'healing'],
        'document' => <<<'MD'
# Moonlit Mending
*2nd-level abjuration*

- **Casting Time:** 1 action
- **Range:** 60 feet
- **Components:** V, S
- **Duration:** Instantaneous

Soft light settles over a creature you can see within range, knitting flesh and easing the mind. The target regains 2d8 + your spellcasting ability modifier hit points, and you may end one effect causing it to be charmed or frightened.

***At Higher Levels.*** When you cast this spell using a slot of 3rd level or higher, the healing increases by 1d8 for each slot level above 2nd.
MD,
    ],
];
