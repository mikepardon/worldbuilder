# Monster stat block — schema & usage

A compendium **monster** stores its 5e stat block as a JSON object at `fields.block` on the
`campaign_compendium_items` row. It is the single source of truth: the editor's live card preview, the
homebrew Markdown that's saved to `document`, and the public reader all render **from this object**.

- **Where it lives:** `CampaignCompendiumItem.fields.block`
- **Canonical shape & defaults:** `App\Support\Statblock::empty()`
- **Sanitiser (untrusted/AI input → canonical):** `App\Support\Statblock::sanitise()`
- **Renderer (block → Markdown):** `App\Support\Statblock::toMarkdown()`
- **View it raw:** in the compendium editor, toggle **CODE** in the preview toolbar to see this exact JSON.

Every field is optional. A missing key falls back to the `empty()` default; an empty string or `null`
means "omit this line entirely" when rendering.

---

## Field reference

### Header (strings)

| Field | Type | Meaning | Renders as |
| --- | --- | --- | --- |
| `size` | string | Creature size (`Tiny`…`Gargantuan`, or free text like `Large`). | Italic subtitle: `_{size} {type}, {alignment}_` |
| `type` | string | Creature type (`humanoid`, `dragon`, `god`, …). Subtype allowed: `fiend (demon)`. | ″ |
| `alignment` | string | e.g. `lawful neutral`. Blank → alignment omitted from the subtitle. | ″ |
| `ac` | string | Armour Class. **Free text** — parenthetical notes allowed (`23 (natural armor)`). | `**Armor Class** {ac}` |
| `hp` | string | Hit points, usually with dice: `616 (32d12 + 408)`. | `**Hit Points** {hp}` |
| `speed` | string | e.g. `40 ft., fly 60 ft. (hover)`. | `**Speed** {speed}` |
| `cr` | string | Challenge rating. **Free text** — XP and mythic notes allowed. | `**Challenge** {cr}` |

> These are stored as **strings**, not numbers — so `ac`, `hp`, and `cr` can carry flavour and
> parentheticals verbatim (e.g. `"23 (the Ninth House will not permit it)"`).

### Ability scores (integers)

`abilities` is an object of six integer scores, each clamped to **1–30** by the sanitiser. The modifier is
computed on render (`floor((score - 10) / 2)`), so you never store the modifier.

```json
"abilities": { "str": 26, "dex": 20, "con": 30, "int": 27, "wis": 28, "cha": 26 }
```

Renders as the six-column table, e.g. `str: 26` → `26 (+8)`.

### Defences, senses & languages (strings)

Each is a single string; blank/`null` omits the line.

| Field | Renders as |
| --- | --- |
| `saves` | `**Saving Throws** {saves}` — e.g. `Con +19, Int +17, Wis +18, Cha +17` |
| `skills` | `**Skills** {skills}` — e.g. `Arcana +17, Insight +27` |
| `vulnerabilities` | `**Damage Vulnerabilities** {…}` |
| `resistances` | `**Damage Resistances** {…}` |
| `immunities` | `**Damage Immunities** {…}` |
| `conditionImmunities` | `**Condition Immunities** {…}` |
| `senses` | `**Senses** {…}` — e.g. `truesight 240 ft., passive Perception 28` |
| `languages` | `**Languages** {…}` |
| `legendaryResistance` | `**Legendary Resistances** {…}` — a summary line (distinct from a trait, see below) |

### Feature groups (arrays of `{ name, desc }`)

Six ordered lists. Each entry is `{ "name": "…", "desc": "…" }`; the sanitiser keeps an entry if either
`name` or `desc` is non-empty. An empty array renders **nothing** (its heading is skipped).

| Field | Section heading | Notes |
| --- | --- | --- |
| `traits` | `### Traits` | Passive features. Spellcasting (below) is prepended here. |
| `actions` | `### Actions` | |
| `bonusActions` | `### Bonus Actions` | |
| `reactions` | `### Reactions` | |
| `legendary` | `### Legendary Actions` | |
| `mythic` | `### Mythic Actions` | |

Each entry renders as `***{name}.*** {desc}`.

```json
"traits": [
  { "name": "Legendary Resistance (5/Day)", "desc": "If Hades fails a saving throw, he can choose to succeed instead." }
]
```

### `spellcasting` (object or `null`)

`null` when the creature doesn't cast. When present, it's rendered as a **Spellcasting** trait prepended to
`traits`:

```json
"spellcasting": {
  "intro": "Hades casts the following, requiring no components (spell save DC 25):",
  "groups": [
    { "label": "At will", "spells": [ { "name": "detect thoughts" }, { "name": "dispel magic" } ] },
    { "label": "3/day each", "spells": [ { "name": "power word kill" } ] }
  ]
}
```

Renders the intro, then one italicised line per group: `**{label}:** *spell*, *spell*`.

---

## Two ways to express "Legendary Resistance"

Your Hades example uses **both** styles that the schema supports — pick whichever reads best:

1. **Summary line** — the `legendaryResistance` string field → renders `**Legendary Resistances** {value}`.
2. **A trait** — an entry in `traits` named `Legendary Resistance (5/Day)` with the full rules text.

They are independent; using one does not populate the other.

---

## Sanitisation rules (what `sanitise()` enforces)

Applied to any untrusted block (AI output, imports) before it's trusted/rendered:

- **String fields**: kept only when a non-empty string/number; otherwise the `empty()` default is used.
- **Ability scores**: coerced to int and clamped to **1–30**.
- **Feature groups**: each normalised to `{ name, desc }`; blank rows dropped; non-arrays become `[]`.
- **`spellcasting`**: left as-is (`null` unless set in the editor).
- On render, `toMarkdown()` also drops any `null` values (Laravel turns empty form inputs into `null`) and
  **skips the line/section** for empty values — so absent fields never leave stray headings.

Origin mapping: importing an Open5e monster (`Statblock::fromOpen5e()`) fills these same keys —
`special_abilities → traits`, `actions`, `bonus_actions → bonusActions`, `reactions`,
`legendary_actions → legendary`, `mythic_actions → mythic`.

---

## Worked example — Hades

### Stored JSON (`fields.block`)

```json
{
  "size": "Large",
  "type": "god",
  "alignment": "lawful neutral",
  "ac": "23 (the Ninth House will not permit it)",
  "hp": "616 (32d12 + 408)",
  "speed": "40 ft., fly 60 ft. (hover)",
  "cr": "26 (90,000 XP) — as a mythic encounter, CR 28 (120,000 XP)",
  "abilities": { "str": 26, "dex": 20, "con": 30, "int": 27, "wis": 28, "cha": 26 },
  "saves": "Con +19, Int +17, Wis +18, Cha +17",
  "skills": "Arcana +17, Insight +27, Intimidation +17, Perception +18",
  "resistances": "cold, fire, lightning, thunder, radiant",
  "immunities": "necrotic, poison; bludgeoning, piercing, and slashing from nonmagical attacks",
  "conditionImmunities": "blinded, charmed, deafened, exhaustion, frightened, paralysed, petrified, poisoned, stunned",
  "vulnerabilities": null,
  "legendaryResistance": null,
  "senses": "truesight 240 ft., passive Perception 28",
  "languages": "all; he does not need to be present to be heard",
  "traits": [
    { "name": "Legendary Resistance (5/Day)", "desc": "If Hades fails a saving throw, he can choose to succeed instead." },
    { "name": "The Door Does Not Open For Him", "desc": "Hades cannot die by any means available in this world. If reduced to 0 hit points he is not slain, destroyed, banished, or unmade — see Mythic Trait: The House Will Not Have Him. He cannot be banished, imprisoned, planar bound, turned, or forced through any portal against his will." }
  ],
  "actions": [],
  "bonusActions": [],
  "reactions": [],
  "legendary": [],
  "mythic": [],
  "spellcasting": null
}
```

### Rendered Markdown (`toMarkdown(block, "Hades")`)

```markdown
# Hades

_Large god, lawful neutral_

---

**Armor Class** 23 (the Ninth House will not permit it)
**Hit Points** 616 (32d12 + 408)
**Speed** 40 ft., fly 60 ft. (hover)

| STR | DEX | CON | INT | WIS | CHA |
| --- | --- | --- | --- | --- | --- |
| 26 (+8) | 20 (+5) | 30 (+10) | 27 (+8) | 28 (+9) | 26 (+8) |

---

**Saving Throws** Con +19, Int +17, Wis +18, Cha +17
**Skills** Arcana +17, Insight +27, Intimidation +17, Perception +18
**Damage Resistances** cold, fire, lightning, thunder, radiant
**Damage Immunities** necrotic, poison; bludgeoning, piercing, and slashing from nonmagical attacks
**Condition Immunities** blinded, charmed, deafened, exhaustion, frightened, paralysed, petrified, poisoned, stunned
**Senses** truesight 240 ft., passive Perception 28
**Languages** all; he does not need to be present to be heard
**Challenge** 26 (90,000 XP) — as a mythic encounter, CR 28 (120,000 XP)

### Traits

***Legendary Resistance (5/Day).*** If Hades fails a saving throw, he can choose to succeed instead.

***The Door Does Not Open For Him.*** Hades cannot die by any means available in this world. If reduced to 0 hit points he is not slain, destroyed, banished, or unmade — see Mythic Trait: The House Will Not Have Him. He cannot be banished, imprisoned, planar bound, turned, or forced through any portal against his will.
```

Note how the empty groups (`actions`, `bonusActions`, `reactions`, `legendary`, `mythic`) and the
`null` fields (`vulnerabilities`, `legendaryResistance`, `spellcasting`) produce **no output** — the
renderer skips every empty line and heading.
```
