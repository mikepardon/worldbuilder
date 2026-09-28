<?php

namespace Database\Seeders;

use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Support\CompendiumFields;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A starter set of hand-authored feats and abilities in the Worldbuilder library, so the Actions/Traits
 * and Abilities tabs have real content to grant out of the box. Idempotent by (item_type, slug).
 */
class WorldbuilderContentSeeder extends Seeder
{
    /** @var list<array{slug: string, name: string, summary: string, fields: array<string, string>}> */
    private const FEATS = [
        ['slug' => 'alert', 'name' => 'Alert', 'summary' => 'Always on guard.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => '+5 to initiative. You can\'t be surprised while conscious, and attackers gain no advantage from being hidden from you.',
        ]],
        ['slug' => 'sentinel', 'name' => 'Sentinel', 'summary' => 'Punish those who slip past.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Reaction',
            'description' => 'When a creature within reach makes an attack against a target other than you, you may make a melee attack against it as a reaction.',
        ]],
        ['slug' => 'great-weapon-master', 'name' => 'Great Weapon Master', 'summary' => 'Trade accuracy for devastation.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Bonus action',
            'description' => 'On a critical hit or a kill with a melee weapon, make one melee attack as a bonus action. You may take −5 to hit for +10 damage with heavy weapons.',
        ]],
        ['slug' => 'tough', 'name' => 'Tough', 'summary' => 'Harder to put down.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'Your hit point maximum increases by an amount equal to twice your level.',
        ]],
        ['slug' => 'lucky', 'name' => 'Lucky', 'summary' => 'Fortune favours you.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'You have three luck points. Spend one to reroll an attack, ability check or save, or to force an attacker to reroll. Regain them on a long rest.',
        ]],
        ['slug' => 'mobile', 'name' => 'Mobile', 'summary' => 'Fast and elusive.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => '+10 ft speed. Difficult terrain costs nothing when you Dash, and melee attacks against a creature don\'t provoke opportunity attacks from it this turn.',
        ]],
        ['slug' => 'war-caster', 'name' => 'War Caster', 'summary' => 'Cast amid the fray.', 'fields' => [
            'prerequisite' => 'The ability to cast a spell', 'activation' => 'Passive',
            'description' => 'Advantage on concentration saves, you can cast with hands full, and you may cast a spell as an opportunity attack instead of a strike.',
        ]],
        ['slug' => 'resilient', 'name' => 'Resilient', 'summary' => 'Shore up a weakness.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'Increase one ability score by 1 and gain proficiency in saving throws using that ability.',
        ]],
        ['slug' => 'savage-attacker', 'name' => 'Savage Attacker', 'summary' => 'Hit them where it hurts.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'Once per turn when you roll damage for a melee weapon attack, you may reroll the dice and use either total.',
        ]],
        ['slug' => 'skilled', 'name' => 'Skilled', 'summary' => 'A broad education.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'Gain proficiency in any combination of three skills or tools of your choice.',
        ]],
        ['slug' => 'magic-initiate', 'name' => 'Magic Initiate', 'summary' => 'A dabbler\'s spark.', 'fields' => [
            'prerequisite' => '—', 'activation' => 'Passive',
            'description' => 'Learn two cantrips and one 1st-level spell from a chosen class; you can cast the 1st-level spell once per long rest.',
        ]],
    ];

    /** @var list<array{slug: string, name: string, summary: string, fields: array<string, string>}> */
    private const ABILITIES = [
        ['slug' => 'second-wind', 'name' => 'Second Wind', 'summary' => 'A burst of resilience.', 'fields' => [
            'activation' => 'Bonus action', 'uses' => '1 / short rest',
            'description' => 'You regain 1d10 + your level hit points.',
        ]],
        ['slug' => 'action-surge', 'name' => 'Action Surge', 'summary' => 'A moment of heightened focus.', 'fields' => [
            'activation' => 'Action', 'uses' => '1 / short rest',
            'description' => 'You take one additional action on your turn.',
        ]],
        ['slug' => 'rage', 'name' => 'Rage', 'summary' => 'Channel your fury.', 'fields' => [
            'activation' => 'Bonus action', 'uses' => '3 / long rest',
            'description' => 'Gain advantage on Strength checks and saves, bonus melee damage, and resistance to bludgeoning, piercing and slashing damage for one minute.',
        ]],
        ['slug' => 'sneak-attack', 'name' => 'Sneak Attack', 'summary' => 'Strike where it hurts.', 'fields' => [
            'activation' => 'Passive', 'uses' => 'Once per turn',
            'description' => 'Once per turn, deal an extra 1d6 damage (scaling with level) to a target you have advantage against, or one adjacent to an ally.',
        ]],
        ['slug' => 'lay-on-hands', 'name' => 'Lay on Hands', 'summary' => 'A healing pool.', 'fields' => [
            'activation' => 'Action', 'uses' => 'Pool = 5 × level / long rest',
            'description' => 'Touch a creature to restore hit points from your healing pool, or spend 5 points to neutralise one disease or poison.',
        ]],
        ['slug' => 'wild-shape', 'name' => 'Wild Shape', 'summary' => 'Take a beast\'s form.', 'fields' => [
            'activation' => 'Action', 'uses' => '2 / short rest',
            'description' => 'Transform into a beast you have seen, keeping your mental stats. You revert when you drop to 0 hit points or choose to.',
        ]],
        ['slug' => 'bardic-inspiration', 'name' => 'Bardic Inspiration', 'summary' => 'A rousing word.', 'fields' => [
            'activation' => 'Bonus action', 'uses' => 'CHA modifier / long rest',
            'description' => 'Give a creature a Bardic Inspiration die (d6, scaling) it can add to one attack, check or save within ten minutes.',
        ]],
        ['slug' => 'cunning-action', 'name' => 'Cunning Action', 'summary' => 'Quick on your feet.', 'fields' => [
            'activation' => 'Bonus action', 'uses' => 'Each turn',
            'description' => 'Use a bonus action on each of your turns to Dash, Disengage or Hide.',
        ]],
        ['slug' => 'divine-sense', 'name' => 'Divine Sense', 'summary' => 'Feel the sacred and profane.', 'fields' => [
            'activation' => 'Action', 'uses' => '1 + CHA modifier / long rest',
            'description' => 'Until your next turn, you know the location of any celestial, fiend or undead within 60 ft, and any consecrated or desecrated place.',
        ]],
        ['slug' => 'reckless-attack', 'name' => 'Reckless Attack', 'summary' => 'Abandon defence.', 'fields' => [
            'activation' => 'Passive', 'uses' => 'On your first attack',
            'description' => 'Gain advantage on melee Strength attacks this turn, but attacks against you have advantage until your next turn.',
        ]],
        ['slug' => 'uncanny-dodge', 'name' => 'Uncanny Dodge', 'summary' => 'Roll with the blow.', 'fields' => [
            'activation' => 'Reaction', 'uses' => 'Once per turn',
            'description' => 'When an attacker you can see hits you, halve the attack\'s damage against you.',
        ]],
    ];

    /**
     * Spells named to match the Ascendancy starter's spell nodes, so those nodes grant a real entry.
     *
     * @var list<array{slug: string, name: string, summary: string, fields: array<string, string>}>
     */
    private const SPELLS = [
        // The Fire line: one spell learned as ranks. A caster may cast any rank they've learned, paying its
        // mana; the 4th rank (Fireball) can only be learned from character level 5.
        ['slug' => 'firebolt', 'name' => 'Firebolt', 'summary' => 'A fire spell that grows from a dart to a roaring blast.', 'fields' => [
            'level' => 'Cantrip', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => '120 feet', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'The Fire line — a single spell learned as ranks, each a stronger shape of flame.',
            'levels' => [
                ['level' => 1, 'mana' => 2, 'min_level' => 1, 'name' => 'Firebolt', 'casting_time' => '1 action', 'range' => '120 feet', 'components' => 'V, S', 'targets' => 'One creature', 'duration' => 'Instantaneous', 'description' => 'Hurl a mote of fire for 1d10 fire damage on a hit.'],
                ['level' => 2, 'mana' => 4, 'min_level' => 1, 'name' => 'Fire Blast', 'casting_time' => '1 action', 'range' => '60 feet', 'components' => 'V, S', 'targets' => 'One creature', 'duration' => 'Instantaneous', 'description' => 'A heavier bolt deals 3d6 fire damage on a hit.'],
                ['level' => 3, 'mana' => 6, 'min_level' => 3, 'name' => 'Fire Cone', 'casting_time' => '1 action', 'range' => 'Self (15-ft cone)', 'components' => 'V, S', 'targets' => 'Each creature in a cone', 'duration' => 'Instantaneous', 'description' => 'Flame gouts from your hand; each creature in a 15-ft cone takes 5d6 fire damage (DEX save for half).'],
                ['level' => 4, 'mana' => 8, 'min_level' => 5, 'name' => 'Fireball', 'casting_time' => '1 action', 'range' => '150 feet', 'components' => 'V, S, M', 'targets' => '20-ft radius', 'duration' => 'Instantaneous', 'description' => 'A streak blossoms into an explosion; each creature in a 20-ft radius takes 8d6 fire damage (DEX save for half).'],
            ],
        ]],
        // A leveled spell whose ranks change duration and targets rather than damage.
        ['slug' => 'invisibility', 'name' => 'Invisibility', 'summary' => 'Fade from sight — longer, and for others, at higher ranks.', 'fields' => [
            'level' => '2nd', 'school' => 'Illusion', 'casting_time' => '1 action', 'range' => 'Self', 'components' => 'V, S', 'duration' => 'Up to 10 minutes',
            'description' => 'Bend light around a creature so it cannot be seen.',
            'levels' => [
                ['level' => 1, 'mana' => 3, 'min_level' => 1, 'name' => 'Invisibility', 'casting_time' => '1 action', 'range' => 'Self', 'components' => 'V, S', 'targets' => 'Self', 'duration' => 'Up to 10 minutes', 'description' => 'You become invisible for up to 10 minutes, until you attack or cast a spell.'],
                ['level' => 2, 'mana' => 5, 'min_level' => 3, 'name' => 'Invisibility', 'casting_time' => '1 action', 'range' => 'Self', 'components' => 'V, S', 'targets' => 'Self', 'duration' => 'Up to 1 hour', 'description' => 'As rank 1, but the veil lasts up to 1 hour.'],
                ['level' => 3, 'mana' => 7, 'min_level' => 5, 'name' => 'Greater Invisibility', 'casting_time' => '1 action', 'range' => 'Touch', 'components' => 'V, S', 'targets' => 'Self or one creature you touch', 'duration' => 'Up to 1 hour', 'description' => 'Extend the veil to another willing creature you touch, for up to 1 hour.'],
            ],
        ]],
        ['slug' => 'vicious-mockery', 'name' => 'Vicious Mockery', 'summary' => 'A cutting jeer.', 'fields' => [
            'level' => 'Cantrip', 'school' => 'Enchantment', 'casting_time' => '1 action', 'range' => '60 feet', 'components' => 'V', 'duration' => 'Instantaneous',
            'description' => 'A string of insults deals 1d4 psychic damage and imposes disadvantage on the target\'s next attack (WIS save negates).',
        ]],
        ['slug' => 'charm-person', 'name' => 'Charm Person', 'summary' => 'Bend a mind to friendliness.', 'fields' => [
            'level' => '1st', 'school' => 'Enchantment', 'casting_time' => '1 action', 'range' => '30 feet', 'components' => 'V, S', 'duration' => '1 hour',
            'description' => 'A humanoid you can see must succeed on a WIS save or regard you as a friendly acquaintance for the duration.',
        ]],
        ['slug' => 'mend', 'name' => 'Mend', 'summary' => 'A healing touch.', 'fields' => [
            'level' => '1st', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => 'Touch', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'A creature you touch regains 1d8 + your spellcasting modifier hit points.',
        ]],
        ['slug' => 'mass-healing', 'name' => 'Mass Healing', 'summary' => 'Mend the whole party.', 'fields' => [
            'level' => '3rd', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => '60 feet', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'Up to six creatures of your choice in range each regain 3d8 hit points.',
        ]],
        ['slug' => 'entangle', 'name' => 'Entangle', 'summary' => 'Grasping growth.', 'fields' => [
            'level' => '1st', 'school' => 'Conjuration', 'casting_time' => '1 action', 'range' => '90 feet', 'components' => 'V, S', 'duration' => 'Concentration, 1 minute',
            'description' => 'Grasping weeds and vines sprout in a 20-foot square; creatures there are restrained (STR save negates).',
        ]],
        ['slug' => 'shield', 'name' => 'Shield', 'summary' => 'An invisible barrier.', 'fields' => [
            'level' => '1st', 'school' => 'Abjuration', 'casting_time' => '1 reaction', 'range' => 'Self', 'components' => 'V, S', 'duration' => '1 round',
            'description' => 'An instant barrier grants +5 AC until the start of your next turn, including against the triggering attack.',
        ]],
        ['slug' => 'counterspell', 'name' => 'Counterspell', 'summary' => 'Interrupt a caster.', 'fields' => [
            'level' => '3rd', 'school' => 'Abjuration', 'casting_time' => '1 reaction', 'range' => '60 feet', 'components' => 'S', 'duration' => 'Instantaneous',
            'description' => 'Attempt to interrupt a creature in the act of casting a spell; a 3rd-level or lower spell fails automatically.',
        ]],
        ['slug' => 'command', 'name' => 'Command', 'summary' => 'A word of power.', 'fields' => [
            'level' => '1st', 'school' => 'Enchantment', 'casting_time' => '1 bonus action', 'range' => '60 feet', 'components' => 'V', 'duration' => '1 round',
            'description' => 'Speak a one-word command a creature must obey on its next turn (WIS save negates).',
        ]],
        ['slug' => 'magic-missile', 'name' => 'Magic Missile', 'summary' => 'Unerring darts of force.', 'fields' => [
            'level' => '1st', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => '120 feet', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'Three darts of magical force each strike a target you can see for 1d4+1 force damage, no attack roll needed.',
        ]],
        ['slug' => 'cure-wounds', 'name' => 'Cure Wounds', 'summary' => 'Mend by touch.', 'fields' => [
            'level' => '1st', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => 'Touch', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'A creature you touch regains 1d8 + your spellcasting modifier hit points.',
        ]],
        ['slug' => 'healing-word', 'name' => 'Healing Word', 'summary' => 'Heal from afar.', 'fields' => [
            'level' => '1st', 'school' => 'Evocation', 'casting_time' => '1 bonus action', 'range' => '60 feet', 'components' => 'V', 'duration' => 'Instantaneous',
            'description' => 'A creature of your choice in range regains 1d4 + your spellcasting modifier hit points.',
        ]],
        ['slug' => 'bless', 'name' => 'Bless', 'summary' => 'A benediction.', 'fields' => [
            'level' => '1st', 'school' => 'Enchantment', 'casting_time' => '1 action', 'range' => '30 feet', 'components' => 'V, S, M', 'duration' => 'Concentration, 1 minute',
            'description' => 'Up to three creatures add a d4 to their attack rolls and saving throws for the duration.',
        ]],
        ['slug' => 'sleep', 'name' => 'Sleep', 'summary' => 'A drowsing haze.', 'fields' => [
            'level' => '1st', 'school' => 'Enchantment', 'casting_time' => '1 action', 'range' => '90 feet', 'components' => 'V, S, M', 'duration' => '1 minute',
            'description' => 'Roll 5d8; creatures in a 20-foot radius fall unconscious in ascending order of current hit points until the total is exhausted.',
        ]],
        ['slug' => 'thunderwave', 'name' => 'Thunderwave', 'summary' => 'A wave of force.', 'fields' => [
            'level' => '1st', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => 'Self (15-ft cube)', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'Each creature in a 15-foot cube takes 2d8 thunder damage and is pushed 10 ft (CON save halves and negates the push).',
        ]],
        ['slug' => 'misty-step', 'name' => 'Misty Step', 'summary' => 'Blink through the veil.', 'fields' => [
            'level' => '2nd', 'school' => 'Conjuration', 'casting_time' => '1 bonus action', 'range' => 'Self', 'components' => 'V', 'duration' => 'Instantaneous',
            'description' => 'Wreathed in silvery mist, you teleport up to 30 feet to a space you can see.',
        ]],
        ['slug' => 'hold-person', 'name' => 'Hold Person', 'summary' => 'Freeze a foe.', 'fields' => [
            'level' => '2nd', 'school' => 'Enchantment', 'casting_time' => '1 action', 'range' => '60 feet', 'components' => 'V, S, M', 'duration' => 'Concentration, 1 minute',
            'description' => 'A humanoid you can see is paralysed (WIS save negates, and it repeats the save at the end of each of its turns).',
        ]],
        ['slug' => 'lightning-bolt', 'name' => 'Lightning Bolt', 'summary' => 'A stroke of lightning.', 'fields' => [
            'level' => '3rd', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => 'Self (100-ft line)', 'components' => 'V, S, M', 'duration' => 'Instantaneous',
            'description' => 'A line of lightning 100 ft long deals 8d6 lightning damage to each creature in it (DEX save for half).',
        ]],
        ['slug' => 'haste', 'name' => 'Haste', 'summary' => 'Quickened by magic.', 'fields' => [
            'level' => '3rd', 'school' => 'Transmutation', 'casting_time' => '1 action', 'range' => '30 feet', 'components' => 'V, S, M', 'duration' => 'Concentration, 1 minute',
            'description' => 'A willing creature gains +2 AC, advantage on DEX saves, doubled speed and an extra limited action each turn.',
        ]],
        ['slug' => 'sacred-flame', 'name' => 'Sacred Flame', 'summary' => 'Radiant judgement.', 'fields' => [
            'level' => 'Cantrip', 'school' => 'Evocation', 'casting_time' => '1 action', 'range' => '60 feet', 'components' => 'V, S', 'duration' => 'Instantaneous',
            'description' => 'Flame-like radiance descends on a creature; it takes 1d8 radiant damage (scaling) with no benefit from cover (DEX save negates).',
        ]],
    ];

    /**
     * Races whose base modifiers are keyed to the Ascendancy starter's stats (str/dex/int/wis/cha), so a
     * preview character reflects its race before any points are spent.
     *
     * @var list<array{slug: string, name: string, summary: string, fields: array<string, mixed>}>
     */
    private const RACES = [
        ['slug' => 'human', 'name' => 'Human', 'summary' => 'Versatile and adaptable.', 'fields' => [
            'size' => 'Medium', 'speed' => '30 feet', 'ability_bonuses' => '+1 Strength, +1 Dexterity, +1 Wisdom',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'str', 'delta' => 1],
                ['type' => 'stat', 'key' => 'dex', 'delta' => 1],
                ['type' => 'stat', 'key' => 'wis', 'delta' => 1],
            ],
            'description' => 'Humans are the most adaptable folk, found in every corner of the world.',
        ]],
        ['slug' => 'elf', 'name' => 'Elf', 'summary' => 'Graceful and keen.', 'fields' => [
            'size' => 'Medium', 'speed' => '30 feet', 'ability_bonuses' => '+2 Dexterity, +1 Intelligence',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'dex', 'delta' => 2],
                ['type' => 'stat', 'key' => 'int', 'delta' => 1],
            ],
            'description' => 'Elves are a magical people of otherworldly grace, keen senses and long memory.',
        ]],
        ['slug' => 'dwarf', 'name' => 'Dwarf', 'summary' => 'Stout and resolute.', 'fields' => [
            'size' => 'Medium', 'speed' => '25 feet', 'ability_bonuses' => '+2 Strength, +1 Wisdom',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'str', 'delta' => 2],
                ['type' => 'stat', 'key' => 'wis', 'delta' => 1],
            ],
            'description' => 'Bold and hardy, dwarves are skilled warriors, miners and workers of stone and metal.',
        ]],
        ['slug' => 'halfling', 'name' => 'Halfling', 'summary' => 'Small, nimble and lucky.', 'fields' => [
            'size' => 'Small', 'speed' => '25 feet', 'ability_bonuses' => '+2 Dexterity, +1 Charisma',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'dex', 'delta' => 2],
                ['type' => 'stat', 'key' => 'cha', 'delta' => 1],
            ],
            'description' => 'Halflings are an affable, cheerful folk who value home, hearth and a bit of good luck.',
        ]],
        ['slug' => 'half-orc', 'name' => 'Half-Orc', 'summary' => 'Fierce and enduring.', 'fields' => [
            'size' => 'Medium', 'speed' => '30 feet', 'ability_bonuses' => '+2 Strength, +1 Constitution',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'str', 'delta' => 2],
                ['type' => 'stat', 'key' => 'wis', 'delta' => 1],
            ],
            'description' => 'Half-orcs are marked by their orc heritage — powerful, quick to anger and slow to fall.',
        ]],
        ['slug' => 'tiefling', 'name' => 'Tiefling', 'summary' => 'Touched by the infernal.', 'fields' => [
            'size' => 'Medium', 'speed' => '30 feet', 'ability_bonuses' => '+2 Charisma, +1 Intelligence',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'cha', 'delta' => 2],
                ['type' => 'stat', 'key' => 'int', 'delta' => 1],
            ],
            'description' => 'Bearing the mark of a fiendish bloodline, tieflings walk the world with horns, wit and a will of their own.',
        ]],
        ['slug' => 'gnome', 'name' => 'Gnome', 'summary' => 'Curious and clever.', 'fields' => [
            'size' => 'Small', 'speed' => '25 feet', 'ability_bonuses' => '+2 Intelligence, +1 Dexterity',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'int', 'delta' => 2],
                ['type' => 'stat', 'key' => 'dex', 'delta' => 1],
            ],
            'description' => 'Gnomes are inventive, inquisitive folk who bring boundless enthusiasm to magic, tinkering and mischief.',
        ]],
        ['slug' => 'dragonborn', 'name' => 'Dragonborn', 'summary' => 'Proud and draconic.', 'fields' => [
            'size' => 'Medium', 'speed' => '30 feet', 'ability_bonuses' => '+2 Strength, +1 Charisma',
            'stat_modifiers' => [
                ['type' => 'stat', 'key' => 'str', 'delta' => 2],
                ['type' => 'stat', 'key' => 'cha', 'delta' => 1],
            ],
            'description' => 'Dragonborn carry the blood of dragons, exhaling elemental breath and prizing honour above all.',
        ]],
    ];

    /** @var list<array{slug: string, name: string, summary: string, fields: array<string, string>}> */
    private const CONDITIONS = [
        ['slug' => 'blinded', 'name' => 'Blinded', 'summary' => 'Cannot see.', 'fields' => ['description' => 'A blinded creature can\'t see and automatically fails any check requiring sight. Attacks against it have advantage, and its own attacks have disadvantage.']],
        ['slug' => 'charmed', 'name' => 'Charmed', 'summary' => 'Beguiled.', 'fields' => ['description' => 'A charmed creature can\'t attack the charmer or target it with harmful effects, and the charmer has advantage on social checks with it.']],
        ['slug' => 'frightened', 'name' => 'Frightened', 'summary' => 'Gripped by fear.', 'fields' => ['description' => 'A frightened creature has disadvantage on checks and attacks while the source of its fear is in sight, and can\'t willingly move closer to it.']],
        ['slug' => 'grappled', 'name' => 'Grappled', 'summary' => 'Held fast.', 'fields' => ['description' => 'A grappled creature\'s speed becomes 0. The condition ends if the grappler is incapacitated or the creatures are forced apart.']],
        ['slug' => 'paralyzed', 'name' => 'Paralyzed', 'summary' => 'Unable to move.', 'fields' => ['description' => 'A paralyzed creature is incapacitated, can\'t move or speak, and fails STR and DEX saves. Attacks against it have advantage, and hits within 5 ft are critical.']],
        ['slug' => 'poisoned', 'name' => 'Poisoned', 'summary' => 'Sickened.', 'fields' => ['description' => 'A poisoned creature has disadvantage on attack rolls and ability checks.']],
        ['slug' => 'prone', 'name' => 'Prone', 'summary' => 'Knocked down.', 'fields' => ['description' => 'A prone creature\'s only movement option is to crawl, and it attacks with disadvantage. Melee attacks against it have advantage; ranged attacks have disadvantage.']],
        ['slug' => 'restrained', 'name' => 'Restrained', 'summary' => 'Bound in place.', 'fields' => ['description' => 'A restrained creature\'s speed is 0, its attacks have disadvantage, attacks against it have advantage, and it has disadvantage on DEX saves.']],
        ['slug' => 'stunned', 'name' => 'Stunned', 'summary' => 'Reeling.', 'fields' => ['description' => 'A stunned creature is incapacitated, can\'t move, and can speak only falteringly. It fails STR and DEX saves, and attacks against it have advantage.']],
    ];

    /** @var list<array{slug: string, name: string, summary: string, fields: array<string, string>}> */
    private const MAGIC_ITEMS = [
        ['slug' => 'bag-of-holding', 'name' => 'Bag of Holding', 'summary' => 'A pocket dimension.', 'fields' => ['category' => 'Wondrous item', 'rarity' => 'Uncommon', 'attunement' => 'No', 'description' => 'This bag holds far more than its size suggests — up to 500 pounds in an extradimensional space, always weighing 15 pounds.']],
        ['slug' => 'cloak-of-protection', 'name' => 'Cloak of Protection', 'summary' => 'A warding mantle.', 'fields' => ['category' => 'Wondrous item', 'rarity' => 'Uncommon', 'attunement' => 'Yes', 'description' => 'You gain a +1 bonus to AC and saving throws while wearing this cloak.']],
        ['slug' => 'boots-of-speed', 'name' => 'Boots of Speed', 'summary' => 'Fleet of foot.', 'fields' => ['category' => 'Wondrous item', 'rarity' => 'Rare', 'attunement' => 'Yes', 'description' => 'Click the heels together to double your speed and deny opportunity attacks for up to 10 minutes, once per long rest.']],
        ['slug' => 'ring-of-protection', 'name' => 'Ring of Protection', 'summary' => 'A guarding band.', 'fields' => ['category' => 'Ring', 'rarity' => 'Rare', 'attunement' => 'Yes', 'description' => 'You gain a +1 bonus to AC and saving throws while wearing this ring.']],
        ['slug' => 'wand-of-magic-missiles', 'name' => 'Wand of Magic Missiles', 'summary' => 'Darts on command.', 'fields' => ['category' => 'Wand', 'rarity' => 'Uncommon', 'attunement' => 'No', 'description' => 'Holds 7 charges; expend a charge to cast Magic Missile. It regains 1d6+1 charges at dawn.']],
        ['slug' => 'flame-tongue', 'name' => 'Flame Tongue', 'summary' => 'A blade of fire.', 'fields' => ['category' => 'Weapon', 'rarity' => 'Rare', 'attunement' => 'Yes', 'description' => 'A command word wreathes this sword in flame, adding 2d6 fire damage to each hit until quenched.']],
        ['slug' => 'amulet-of-health', 'name' => 'Amulet of Health', 'summary' => 'Vigour in a gem.', 'fields' => ['category' => 'Wondrous item', 'rarity' => 'Rare', 'attunement' => 'Yes', 'description' => 'Your Constitution score becomes 19 while you wear this amulet (no effect if it is already 19 or higher).']],
    ];

    public function run(): void
    {
        $this->seedInto('worldbuilder-feat', 'feat', self::FEATS);
        $this->seedInto('worldbuilder-ability', 'ability', self::ABILITIES);
        $this->seedInto('worldbuilder-spell', 'spell', self::SPELLS);
        $this->seedInto('worldbuilder-race', 'race', self::RACES);
        $this->seedInto('worldbuilder-condition', 'condition', self::CONDITIONS);
        $this->seedInto('worldbuilder-magicitem', 'magicitem', self::MAGIC_ITEMS);
    }

    /**
     * @param  list<array{slug: string, name: string, summary: string, fields: array<string, string>}>  $entries
     */
    private function seedInto(string $sourceKey, string $itemType, array $entries): void
    {
        $source = CompendiumSource::where('key', $sourceKey)->first();
        if ($source === null) {
            return;
        }

        foreach ($entries as $entry) {
            // Every Ascendancy spell is a line of mana-costed ranks; give any spell without explicit ranks a
            // single rank so it still carries a mana cost.
            $fields = $itemType === 'spell' ? $this->withSpellLevels($entry['fields'], $entry['name']) : $entry['fields'];

            // A 'wb-' prefix keeps these clear of any SRD entry that shares the same slug (e.g. Open5e's
            // "fireball"), so seeding never clobbers imported library content.
            CompendiumItem::updateOrCreate(
                ['item_type' => $itemType, 'slug' => 'wb-'.$entry['slug']],
                [
                    'source_id' => $source->id,
                    'name' => $entry['name'],
                    'summary' => $entry['summary'],
                    'fields' => $fields,
                    'document' => CompendiumFields::toMarkdown($itemType, $fields, $entry['name']),
                    'visible' => true,
                    'fingerprint' => hash('sha256', 'worldbuilder|'.$itemType.'|'.Str::slug($entry['slug'])),
                ],
            );
        }
    }

    /** A sensible mana cost for a synthesised single rank, scaled off the spell's D&D level label. */
    private const MANA_BY_LEVEL = [
        'Cantrip' => 1, '1st' => 2, '2nd' => 3, '3rd' => 5, '4th' => 7,
        '5th' => 9, '6th' => 11, '7th' => 13, '8th' => 15, '9th' => 17,
    ];

    /**
     * Ensure a spell's fields carry a `levels` array. Spells authored with explicit ranks are left alone;
     * a flat spell gets a single rank derived from its facets, with mana scaled off its D&D level.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withSpellLevels(array $fields, string $name): array
    {
        if (isset($fields['levels']) && is_array($fields['levels']) && $fields['levels'] !== []) {
            return $fields;
        }

        $fields['levels'] = [[
            'level' => 1,
            'mana' => self::MANA_BY_LEVEL[(string) ($fields['level'] ?? 'Cantrip')] ?? 2,
            'min_level' => 1,
            'name' => $name,
            'casting_time' => (string) ($fields['casting_time'] ?? ''),
            'range' => (string) ($fields['range'] ?? ''),
            'components' => (string) ($fields['components'] ?? ''),
            'targets' => '',
            'duration' => (string) ($fields['duration'] ?? ''),
            'description' => (string) ($fields['description'] ?? ''),
        ]];

        return $fields;
    }
}
