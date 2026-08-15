<?php

// The continent of Aeria and its capitals, plus Stormhaven and the Ironvein Fortress. Long-form entries
// use the setting's own descriptions; smaller landmarks are kept short. {{NPC:slug}} is a placeholder the
// seeder never resolves — plain prose links are used instead — so keep body text free of embeds here.

return [
    [
        'title' => 'Aeria',
        'slug' => 'aeria',
        'kind' => 'location',
        'summary' => 'One of the six continents of Glieda — a diverse landmass of seven capitals, from the dwarven shipyards of Arcmond to the goblin wood of Gloomgrove.',
        'is_private' => false,
        'tags' => ['continent', 'overview', 'aeria'],
        'data' => [
            'type' => 'Continent',
            'region' => 'Glieda',
            'population' => 'Millions',
        ],
        'content' => <<<'MD'
# Aeria

Aeria, one of the six continents of Glieda, is a diverse and vibrant landmass home to a range of cultures, landscapes, and political systems. From the rugged coastlines and military prowess of Arcmond in the east to the ancient, nature-integrated city of Shendrift in the south, Aeria offers a rich tapestry of environments and societies.

Its geography varies from the fertile valleys of Grimwrath to the arid deserts of Phendor, reflecting the adaptability and resilience of its inhabitants. Aeria's cities are centres of learning, hubs of commerce, bastions of military strength, and archives of ancient wisdom — each contributing uniquely to the continent's identity and balance.

## The Seven Capitals

- **Arcmond** — the dwarven naval power of the eastern waterfront, ruled by a council of generals.
- **Ankmier** — an inland city of many cultures, governed by a collective council.
- **Grimwrath** — a mountain city of faith and craft, ruled by an Archbishop who forbids magic.
- **Phendor** — a desert of learning, home to gnomes and halflings, governed by its academies.
- **Niswick** — the temperate seat of the human elite, ruled by a council of nobles.
- **Shendrift** — the ancient elven city, woven into its forest and guided by a council of elders.
- **Gloomgrove** — the shadowed goblin wood to the south of Phendor.

Aeria, with its seven capitals, presents a microcosm of the broader world of Glieda: the diversity, challenges, and opportunities that come with varying forms of governance, societal norms, and environmental contexts.
MD,
    ],
    [
        'title' => 'Arcmond',
        'slug' => 'arcmond',
        'kind' => 'location',
        'summary' => 'The dwarven naval power of the eastern waterfront, ruled by a council of generals and built on shipbuilding and metalwork.',
        'is_private' => false,
        'tags' => ['capital', 'military', 'dwarf', 'aeria'],
        'data' => [
            'type' => 'City (military)',
            'region' => 'Aeria — eastern waterfront',
            'population' => 'Large',
            'ruler' => 'A council of dwarf generals',
            'threat' => 'Safe',
            'exports' => ['Shipbuilding', 'Metalwork', 'Weaponry'],
        ],
        'content' => <<<'MD'
# Arcmond

Positioned strategically on the eastern waterfront, Arcmond boasts a rugged coastline peppered with cliffs and occasional beaches. The climate is temperate, with wet winters and dry summers, influenced by its proximity to the sea.

## Organisation Structure

Arcmond is under military rule, governed by a council of high-ranking dwarf generals. The society values discipline, strength, and craftsmanship. Military service is highly esteemed, and many of its citizens are veterans or active members of the armed forces.

## Culture

Dwarf architecture dominates, featuring sturdy stone buildings, underground bunkers, and fortifications. The city's military academy, renowned for its rigorous training programs, stands at the heart of Arcmond. The waterfront is lined with docks and shipyards, reflecting the city's naval prowess.

The economy is driven by shipbuilding, metalworking, and trade. Dwarves here are known for their exceptional skills in blacksmithing and engineering, producing some of the finest weaponry and armour in Glieda. Despite its military focus, Arcmond has a vibrant culture with festivals celebrating martial achievements and ancestral heroes.
MD,
    ],
    [
        'title' => 'Ankmier',
        'slug' => 'ankmier',
        'kind' => 'location',
        'summary' => 'A beacon of multiculturalism governed by a collective (unelected) council, famed for its art, music and bustling marketplaces — and a class divide with a hidden underbelly.',
        'is_private' => false,
        'tags' => ['capital', 'trade', 'multicultural', 'aeria'],
        'data' => [
            'type' => 'City (mercantile)',
            'region' => 'Aeria — inland',
            'population' => 'Large',
            'ruler' => 'A collective council (unelected)',
            'threat' => 'Contested',
            'exports' => ['Art', 'Trade', 'Cuisine'],
        ],
        'content' => <<<'MD'
# Ankmier

Ankmier stands as a beacon of multiculturalism and cooperation, governed by a council representing its diverse districts. This inland city flourishes with a mix of cultural architectures and a vibrant marketplace, driven by a balanced economy of agriculture, trade, and art.

## Organisation Structure

The cityscape of Ankmier is a mosaic of architectural styles, reflecting its multicultural inhabitants. The Council Hall, where the governing body meets, is a symbol of unity. It is led by a collective council — but they are not elected officials.

- Fairly well respected; proud and protective.
- Sees itself as a city that helps people from all walks of life, aiming to protect all.
- A clear class divide between rich and poor, with an underbelly where outcasts and thieves gather.
- Deals with multiple cities through commerce and trade.

## Public Agenda

- Protecting its citizens.
- Welcoming and supporting people displaced from other cities.
- Freedom, independence and democracy.
- Cleaning up crime.

## Culture

Ankmier thrives on its diversity, with cultural festivals from different ethnicities celebrated throughout the year. The economy is varied, with agriculture, trade, and craftsmanship as the mainstays. The city is known for its art, music, and culinary traditions.

**Demonym:** Ankors.
MD,
    ],
    [
        'title' => 'Grimwrath',
        'slug' => 'grimwrath',
        'kind' => 'location',
        'summary' => 'A mountain city of faith and martial discipline governed by an Archbishop, wealthy and pious — and with a deep-seated aversion to magic.',
        'is_private' => false,
        'tags' => ['capital', 'religion', 'anti-magic', 'aeria'],
        'data' => [
            'type' => 'City (theocracy)',
            'region' => 'Aeria — mountains',
            'population' => 'Large',
            'ruler' => 'The Archbishop',
            'threat' => 'Contested',
            'exports' => ['Arms & armour', 'Religious artefacts'],
        ],
        'content' => <<<'MD'
# Grimwrath

Grimwrath, a city governed by an Archbishop, combines religious fervour with martial discipline. Surrounded by mountains, its society is characterised by wealth, piety, and a deep-seated aversion to magic. The economy leans heavily on craftsmanship — particularly arms and religious artefacts — reflecting its dual identity of piety and power.

## Organisation Structure

Governed by an Archbishop, Grimwrath's society is deeply religious, with a strong aversion to magic. The city is ruled with an iron fist, where order and discipline are maintained at all costs. Wealth and religious devotion are highly valued, with a clear social hierarchy favouring the devout and affluent. Magic is forbidden, and those accused of witchcraft face severe punishments.

## Culture

Reflecting its military and religious fervour, the city's architecture is dominated by grand cathedrals, fortified structures, and monuments celebrating historic victories over magic users. The Archbishop's Palace, an imposing fortress-like complex, serves as both the spiritual and administrative centre.

**General races:** Human, Dwarf.
MD,
    ],
    [
        'title' => 'Phendor',
        'slug' => 'phendor',
        'kind' => 'location',
        'summary' => 'A desert oasis of knowledge, home to gnomes and halflings and governed by a coalition of academies. The Great Library is its crown jewel.',
        'is_private' => false,
        'tags' => ['capital', 'academia', 'desert', 'aeria'],
        'data' => [
            'type' => 'City (academic)',
            'region' => 'Aeria — desert',
            'population' => 'Large',
            'ruler' => 'A coalition of academic institutions',
            'threat' => 'Safe',
            'exports' => ['Knowledge', 'Research', 'Magical artefacts'],
        ],
        'content' => <<<'MD'
# Phendor

Situated in a vast desert, Phendor is surrounded by sand dunes, mountains, and quarries. The climate is arid, with extreme temperatures — however, ingenious irrigation techniques have allowed for habitable areas and agriculture.

## Organisation Structure

Phendor is a centre of learning, with no centralised government but rather a coalition of academic institutions. Gnomes and Halflings make up the majority of the population, valuing knowledge, innovation, and education above all.

## Culture

The city's architecture features domed buildings, open-air markets, and an extensive network of libraries and schools. The Great Library of Phendor, home to ancient texts and knowledge, is the city's crown jewel.

Phendor's economy is driven by education, research, and the trade of knowledge and magical artefacts. It is a hub for scholars, inventors, and adventurers seeking wisdom. Cultural festivals often celebrate academic achievements and discoveries.
MD,
    ],
    [
        'title' => 'Niswick',
        'slug' => 'niswick',
        'kind' => 'location',
        'summary' => 'The opulent seat of the human elite, ruled by a council of nobles amid rolling hills and verdant farmland. Owner of the island of Stormhaven.',
        'is_private' => false,
        'tags' => ['capital', 'nobility', 'wealth', 'aeria'],
        'data' => [
            'type' => 'City (aristocracy)',
            'region' => 'Aeria — temperate hills',
            'population' => 'Large',
            'ruler' => 'A council of nobles',
            'threat' => 'Safe',
            'exports' => ['Agriculture', 'Fine art', 'Trade'],
        ],
        'content' => <<<'MD'
# Niswick

Niswick is situated in a lush, temperate region known for its rolling hills and verdant landscapes. The climate is mild, with regular rainfall, supporting a prosperous agricultural sector.

## Organisation Structure

Ruled by a human elite, Niswick is characterised by its high society, where lineage and wealth dictate status. The city is run by a council of nobles, who focus on maintaining the status quo and ensuring the prosperity of the upper classes.

## Culture

The city boasts elegant manors, public gardens, and opulent public buildings, reflecting its wealth and sophisticated culture. The Niswick Opera House and the Royal Museum are centres of cultural life.

Niswick's economy is driven by agriculture, trade, and the arts. The city is known for its refined tastes, hosting balls, art exhibitions, and theatrical performances. Education and etiquette are highly valued among its citizens. Some years ago, Niswick purchased the island of **Stormhaven** outright rather than take it by force.
MD,
    ],
    [
        'title' => 'Shendrift',
        'slug' => 'shendrift',
        'kind' => 'location',
        'summary' => 'The ancient elven city, woven into its forest and guided by a council of elders, prizing sustainability, artisan craft and magic.',
        'is_private' => false,
        'tags' => ['capital', 'elf', 'nature', 'aeria'],
        'data' => [
            'type' => 'City (forest realm)',
            'region' => 'Aeria — southern forest',
            'population' => 'Large',
            'ruler' => 'A council of elders',
            'threat' => 'Safe',
            'exports' => ['Artisan crafts', 'Sustainable timber'],
        ],
        'content' => <<<'MD'
# Shendrift

Shendrift, the ancient elven city, harmonises nature with civilisation, guided by a council of elders. Its architecture and lifestyle deeply integrate with the surrounding forest, emphasising sustainability and magic.

The economy focuses on artisan crafts and sustainable forest resources, reflecting the elven commitment to environmental stewardship and harmony. Where Grimwrath fears magic, Shendrift lives alongside it as a matter of course.
MD,
    ],
    [
        'title' => 'Gloomgrove',
        'slug' => 'gloomgrove',
        'kind' => 'location',
        'summary' => 'A dense, shadowed forest south of Phendor — a natural fortress and the misunderstood home of the goblins of Glieda.',
        'is_private' => false,
        'tags' => ['capital', 'goblin', 'forest', 'aeria'],
        'data' => [
            'type' => 'Forest settlement',
            'region' => 'Aeria — south of Phendor',
            'population' => 'A small village',
            'ruler' => 'Goblin elders',
            'threat' => 'Deadly',
            'exports' => ['Foraged goods', 'Trapcraft'],
        ],
        'content' => <<<'MD'
# Gloomgrove

Gloomgrove stands as a stark contrast to the other capitals of Aeria: a dense, dark forest located to the south of Phendor. This sprawling, shadowy woodland is home to the goblins of Glieda, a society often misunderstood by the other races.

The environment of Gloomgrove is a labyrinth of towering ancient trees, underbrush, and hidden caves, creating a natural fortress that the goblins have turned into their sanctuary. It is a small village of goblins — mildly civilised, but not the best educated.

## Creatures of the Grove

The wood is not empty. Goblin scouts are the least of what a traveller might meet — and deeper in, where the caves flood, older and hungrier things stir.

{{MONSTER:grove-goblin}}

{{MONSTER:bog-ghast}}
MD,
    ],
    [
        'title' => 'Stormhaven',
        'slug' => 'stormhaven',
        'kind' => 'location',
        'summary' => 'An isolated island north-east of Arcmond, owned by Niswick and run by its own local council — and home to the inescapable Ironvein Fortress.',
        'is_private' => false,
        'tags' => ['island', 'niswick', 'prison', 'aeria'],
        'data' => [
            'type' => 'Island',
            'region' => 'Glieda — north-east of Arcmond',
            'population' => 'Small',
            'ruler' => 'Niswick (via a local council)',
            'threat' => 'Contested',
            'exports' => ['Import/export trade', 'Prison tourism'],
        ],
        'content' => <<<'MD'
# Stormhaven

Stormhaven is an isolated island, situated north-east of Arcmond. It is owned and operated by the people of Niswick. Many years ago Niswick found a small community on the island and paid its ruling leader a large sum to take control — choosing acquisition by payment rather than by force.

For years the people of Niswick made no distinction between Stormhaven and the mainland. New buildings were erected, food was imported from around the world, and the economy grew stronger and more stable.

## Culture

The people of Stormhaven are happy with their lot. To the south, a thriving community centres on a small port that sees just one ship a month — a vessel owned and operated by the **Ironvein Fortress**, bringing in new prisoners and taking others away. Arcmond receives a tidy sum from Niswick as the go-between for prison transfers.

The southern quarter is full of restaurants, cafés and gift shops, many themed around the prison. Each year an annual event releases a single prisoner, and the people of Stormhaven hold parades and parties — the biggest event of the year.

To the north stands the formidable prison, the **Ironvein Fortress**, which holds some of the very worst of society.

**Demonym:** Stormers.
MD,
    ],
    [
        'title' => 'The Ironvein Fortress',
        'slug' => 'ironvein-fortress',
        'kind' => 'location',
        'summary' => 'A maximum-security prison on Stormhaven, designed to suppress magic and prevent escape. Alt names: the Bastard Box, Iron Hell.',
        'is_private' => false,
        'tags' => ['prison', 'stormhaven', 'dungeon', 'anti-magic'],
        'data' => [
            'type' => 'Prison fortress',
            'region' => 'Stormhaven — northern point',
            'population' => 'Staff, guards and inmates',
            'ruler' => 'Warden Gorlak Ironfist',
            'threat' => 'Deadly',
            'exports' => [],
        ],
        'content' => <<<'MD'
# The Ironvein Fortress

The Ironvein Fortress, located on the isolated island of Stormhaven, looms as a foreboding symbol of justice — or tyranny, depending on who you ask. This maximum-security prison houses criminals of all kinds, from petty thieves to notorious murderers, and has a reputation for being inescapable. Constructed with unyielding stone and reinforced with enchanted metals, the fortress is designed to suppress magic and prevent any attempt at escape.

**Alt names:** the Bastard Box, Iron Hell.

## Facility Overview

- **The Warden's Quarters** — at the northernmost point, a heavily fortified area where Gorlak Ironfist oversees operations, with a private office of maps, surveillance tools and inmate records, and a hidden stash of magical relics for emergencies.
- **Cell Blocks** — 20 standard cells (8×10 feet: straw mattress, sanitation bucket, ventilation slit) and 10 solitary cells deep underground, their walls etched with anti-magic runes.
- **The Mess Hall and Kitchen** — long wooden tables; simple but sufficient meals, run by Head Cook Vark Dregan and two assistants.
- **The Commissary** — small privileges earned through good behaviour or labour, managed by the surly dwarf Durga Stonegrip.
- **The Chapel and Library** — to the south, a place of reflection and learning where Brother Theodric preaches redemption among worn tomes.
- **The Armoury** — guards only; weapons, shields and magical restraints maintained by the smith Jorven Blacksteel.
- **Guard Quarters** — six rooms; guards stay on-site for months at a time.
- **Reception and Processing** — the main entrance, where Elsa Greaves manages documentation and visitor records.
- **Infirmary** — run by Healer Morwen with her apprentice, the gnome Tibbs.
- **The Guard Tower** — a central tower with a panoramic view and an enchanted signalling system.
- **The Dog Kennels** — three tracking hounds cared for by Felix Bray.
- **Labour Workshops** — inmates craft simple goods under the strict half-orc Master Jervis.

## Security

- **Anti-Magic Wristbands** — every inmate, magic user or not, wears one at all times; they suppress magical abilities, making it nearly impossible to cast.
- **Guard Patrols** — rotating shifts ensure no area is left unchecked.
- **Emergency Protocols** — in the rare event of a breakout, guards carry enchanted whistles to summon reinforcements.

Ironvein is a tightly run operation where every aspect of life is controlled. Escape is a near-impossible feat — perfectly designed to test the ingenuity and resourcefulness of your party.
MD,
    ],

    // --- Gloomgrove landmarks ---
    [
        'title' => 'Battlebark Bastion',
        'slug' => 'battlebark-bastion',
        'kind' => 'location',
        'summary' => 'A goblin stronghold grown into the ancient trees of Gloomgrove.',
        'tags' => ['gloomgrove', 'landmark'],
        'data' => ['type' => 'Stronghold', 'region' => 'Gloomgrove'],
        'content' => "# Battlebark Bastion\n\nA fortified warren woven into the living trunks of Gloomgrove's oldest trees — part fortress, part gathering-hall for the goblin clans.",
    ],
    [
        'title' => 'Mossnest Hall',
        'slug' => 'mossnest-hall',
        'kind' => 'location',
        'summary' => 'A moss-carpeted meeting hall where the goblin elders of Gloomgrove hold council.',
        'tags' => ['gloomgrove', 'landmark'],
        'data' => ['type' => 'Hall', 'region' => 'Gloomgrove'],
        'content' => "# Mossnest Hall\n\nDeep in the wood, a hollow of soft moss and low light where Gloomgrove's elders gather to settle disputes and share what little lore the goblins keep.",
    ],

    // --- Ankmier landmarks ---
    [
        'title' => 'House of Cards',
        'slug' => 'house-of-cards',
        'kind' => 'location',
        'summary' => 'A gambling house in Ankmier where fortunes and secrets change hands nightly.',
        'tags' => ['ankmier', 'tavern'],
        'data' => ['type' => 'Gambling house', 'region' => 'Ankmier'],
        'content' => "# House of Cards\n\nAnkmier's most notorious gambling den, where the city's class divide dissolves for a night — and where more than coin is wagered across the tables.",
    ],
    [
        'title' => 'The Flaming Wand',
        'slug' => 'the-flaming-wand',
        'kind' => 'location',
        'summary' => 'A lively Ankmier tavern popular with traders and travellers.',
        'tags' => ['ankmier', 'tavern'],
        'data' => ['type' => 'Tavern', 'region' => 'Ankmier'],
        'content' => "# The Flaming Wand\n\nA warm, loud tavern near Ankmier's marketplace, its sign a stylised wand wreathed in painted fire. A favourite first stop for newcomers to the city.",
    ],
    [
        'title' => 'The Moldy Eye',
        'slug' => 'the-moldy-eye',
        'kind' => 'location',
        'summary' => 'A dive on the edge of Ankmier\'s underbelly, frequented by outcasts and thieves.',
        'is_private' => false,
        'tags' => ['ankmier', 'tavern', 'underbelly'],
        'data' => ['type' => 'Tavern', 'region' => 'Ankmier (underbelly)'],
        'content' => "# The Moldy Eye\n\nDown in the parts of Ankmier the council prefers not to discuss, the Moldy Eye is where the city's outcasts, thieves and fixers do their quiet business.",
    ],

    // --- Grimwrath landmarks ---
    [
        'title' => "St Ulrich's Cathedral",
        'slug' => 'st-ulrichs-cathedral',
        'kind' => 'location',
        'summary' => 'The great cathedral of Grimwrath and seat of the Archbishop\'s faith.',
        'tags' => ['grimwrath', 'religion', 'landmark'],
        'data' => ['type' => 'Cathedral', 'region' => 'Grimwrath'],
        'content' => "# St Ulrich's Cathedral\n\nGrimwrath's towering cathedral, its walls hung with monuments to historic victories over magic-users. Devotion here is public, loud, and expected.",
    ],
    [
        'title' => 'Barrenforge',
        'slug' => 'barrenforge',
        'kind' => 'location',
        'summary' => 'Grimwrath\'s great forge, producing arms, armour and religious artefacts.',
        'tags' => ['grimwrath', 'craft', 'landmark'],
        'data' => ['type' => 'Forge district', 'region' => 'Grimwrath'],
        'content' => "# Barrenforge\n\nThe heart of Grimwrath's craft — a district of hammer and anvil where the city's smiths turn out weapons, armour and sacred artefacts prized across Aeria.",
    ],
    [
        'title' => 'The Lions Mane',
        'slug' => 'the-lions-mane',
        'kind' => 'location',
        'summary' => 'A respectable Grimwrath inn favoured by pilgrims and soldiers.',
        'tags' => ['grimwrath', 'tavern'],
        'data' => ['type' => 'Inn', 'region' => 'Grimwrath'],
        'content' => "# The Lions Mane\n\nA well-kept inn in the shadow of the cathedral, where pilgrims and off-duty soldiers share bread and quiet, watchful conversation.",
    ],
    [
        'title' => 'The Templars Nectar',
        'slug' => 'the-templars-nectar',
        'kind' => 'location',
        'summary' => 'A Grimwrath tavern with a pious clientele and a strict house rule against loose talk of magic.',
        'tags' => ['grimwrath', 'tavern'],
        'data' => ['type' => 'Tavern', 'region' => 'Grimwrath'],
        'content' => "# The Templars Nectar\n\nA tavern run for the devout, where the ale is decent, the sermons are frequent, and any loose talk of magic will see you shown the door — or worse.",
    ],

    // --- Phendor landmarks ---
    [
        'title' => 'University of Oxdohr',
        'slug' => 'university-of-oxdohr',
        'kind' => 'location',
        'summary' => 'Phendor\'s foremost academy and home of the Great Library.',
        'tags' => ['phendor', 'academia', 'landmark'],
        'data' => ['type' => 'University', 'region' => 'Phendor'],
        'content' => "# University of Oxdohr\n\nPhendor's foremost academy, its domes and lecture-halls surrounding the Great Library. Scholars, inventors and adventurers come here from across Glieda in search of wisdom.",
    ],
    [
        'title' => 'Serenity Library',
        'slug' => 'serenity-library',
        'kind' => 'location',
        'summary' => 'A quiet reading-house in Phendor\'s Temple Quarter.',
        'tags' => ['phendor', 'academia'],
        'data' => ['type' => 'Library', 'region' => 'Phendor'],
        'content' => "# Serenity Library\n\nA hushed reading-house in Phendor's Temple Quarter, kept for study and reflection away from the bustle of the Great Library.",
    ],
    [
        'title' => 'Silver Veil Industries',
        'slug' => 'silver-veil-industries',
        'kind' => 'location',
        'summary' => 'A Phendor workshop-guild trading in inventions and magical artefacts.',
        'tags' => ['phendor', 'trade'],
        'data' => ['type' => 'Workshop guild', 'region' => 'Phendor'],
        'content' => "# Silver Veil Industries\n\nA workshop-guild on the market edge of Phendor, dealing in clever inventions and the trade of magical artefacts — some of them of questionable provenance.",
    ],
    [
        'title' => 'Port Nowhere',
        'slug' => 'port-nowhere',
        'kind' => 'location',
        'summary' => 'A remote desert-edge trading post on the far reaches of Phendor.',
        'tags' => ['phendor', 'trade', 'frontier'],
        'data' => ['type' => 'Trading post', 'region' => 'Phendor (frontier)'],
        'content' => "# Port Nowhere\n\nOut where Phendor's irrigation fails and the dunes take over, Port Nowhere is a last stop for caravans and a first stop for anyone who would rather not be found.",
    ],

    // --- Niswick landmark ---
    [
        'title' => 'Niswick Opera House',
        'slug' => 'niswick-opera-house',
        'kind' => 'location',
        'summary' => 'The jewel of Niswick\'s cultural life, host to the season\'s grandest performances.',
        'tags' => ['niswick', 'culture', 'landmark'],
        'data' => ['type' => 'Opera house', 'region' => 'Niswick'],
        'content' => "# Niswick Opera House\n\nThe centre of Niswick's cultural life, where the nobility gather in their finery for the season's grandest performances — and conduct their quietest negotiations between acts.",
    ],
];
