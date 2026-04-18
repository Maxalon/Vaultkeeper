// Mock card data — realistic MTG-ish
// Colors: W U B R G C (colorless) M (multi) land

const SETS = [
  { code: 'TDM', name: 'Tarkir Dragonstorm', count: 246 },
  { code: 'MH3', name: 'Modern Horizons 3', count: 480 },
  { code: 'LCI', name: 'Lorwyn Eclipsed', count: 259 },
  { code: 'FDN', name: 'Foundations', count: 235 },
  { code: 'EOE', name: 'Edge of Eternities', count: 270 },
  { code: 'DSK', name: 'Duskmourn', count: 274 },
  { code: 'LTR', name: 'Avatar The Last Airbender', count: 326 },
  { code: 'ATH', name: 'Aetherdrift', count: 260 },
];

// Card generator: each card has name, cost, color, type, rarity, pt, rules, set, num
const CARD_POOL = [
  // White
  { n: "Aang, the Last Arbiter", c: "3W", col: "W", t: "Legendary Creature — Monk", r: "mythic", pt: "4/4", rules: "Whenever Aang attacks, exile target nonland permanent.", qty: 2 },
  { n: "Aang's Defense", c: "1W", col: "W", t: "Instant", r: "uncommon", rules: "Prevent all combat damage that would be dealt this turn.", qty: 1 },
  { n: "Aang's Journey", c: "2W", col: "W", t: "Enchantment — Saga", r: "rare", rules: "I — Create a 1/1 white Monk. II — Scry 2. III — Transform target creature you control.", qty: 3 },
  { n: "Aardvark Sloth", c: "3W", col: "W", t: "Creature — Beast", r: "common", pt: "2/4", rules: "Vigilance.", qty: 1 },
  { n: "Aatchik, Emerald Radian", c: "2WW", col: "W", t: "Legendary Creature — Angel", r: "mythic", pt: "3/4", rules: "Flying, lifelink. Other Angels you control get +1/+1.", qty: 1 },
  { n: "Abandon Attachments", c: "1W", col: "W", t: "Sorcery", r: "common", rules: "Destroy all Auras and Equipment.", qty: 4 },
  { n: "Abiding Grace", c: "2W", col: "W", t: "Enchantment", r: "rare", rules: "At the beginning of your upkeep, return a creature card with mana value 2 or less from your graveyard to the battlefield.", qty: 1 },
  { n: "Abolish the Old Ways", c: "3W", col: "W", t: "Sorcery", r: "uncommon", rules: "Destroy all artifacts and enchantments.", qty: 2 },
  { n: "Absolver Thrull", c: "WB", col: "M", t: "Creature — Thrull Cleric", r: "uncommon", pt: "1/2", rules: "When Absolver Thrull enters, destroy target enchantment.", qty: 2 },
  { n: "Abyssal Harvester", c: "3B", col: "B", t: "Creature — Horror", r: "rare", pt: "3/3", rules: "Whenever Abyssal Harvester deals combat damage, each opponent loses 2 life.", qty: 1 },

  // Blue
  { n: "Crashing Wave", c: "2U", col: "U", t: "Instant", r: "uncommon", rules: "Return up to two target creatures to their owners' hands.", qty: 3 },
  { n: "Echoing Truth", c: "1U", col: "U", t: "Instant", r: "common", rules: "Return target nonland permanent and all other permanents with the same name to their owners' hands.", qty: 1 },
  { n: "Eclipsed Boggart", c: "2U", col: "U", t: "Creature — Faerie Rogue", r: "common", pt: "2/2", rules: "Flying. When Eclipsed Boggart enters, draw a card.", qty: 2 },
  { n: "Eclipsed Elf", c: "1U", col: "U", t: "Creature — Elf Wizard", r: "uncommon", pt: "1/1", rules: "Whenever you cast an instant or sorcery, scry 1.", qty: 2 },
  { n: "Eclipsed Flamekin", c: "2UR", col: "M", t: "Creature — Elemental", r: "rare", pt: "3/2", rules: "Haste. Whenever this deals damage, draw a card.", qty: 1 },
  { n: "Eclipsed Kithkin", c: "WU", col: "M", t: "Creature — Kithkin Soldier", r: "uncommon", pt: "2/2", rules: "Flying, vigilance.", qty: 2 },
  { n: "Eclipsed Merrow", c: "2U", col: "U", t: "Creature — Merfolk Wizard", r: "common", pt: "2/3", rules: "Prowess. Other Merfolk you control get +1/+0.", qty: 3 },
  { n: "Edge Rover", c: "3U", col: "U", t: "Artifact Creature — Construct", r: "uncommon", pt: "4/4", rules: "Ward 2.", qty: 1 },
  { n: "Effortless Master", c: "1UU", col: "U", t: "Creature — Human Wizard", r: "rare", pt: "2/3", rules: "Whenever you cast a spell, this creature gains hexproof until end of turn.", qty: 1 },
  { n: "Eidolon of Blossoms", c: "2G", col: "G", t: "Enchantment Creature — Spirit", r: "rare", pt: "2/2", rules: "Constellation — Whenever this or another enchantment enters, draw a card.", qty: 2 },
  { n: "Eight-and-a-Half-Tails", c: "1W", col: "W", t: "Legendary Creature — Fox Cleric", r: "rare", pt: "2/2", rules: "{1}{W}: Target permanent gains protection from white until end of turn.", qty: 1 },
  { n: "Eirdu, Carrier of Dawn", c: "2WU", col: "M", t: "Legendary Creature — Angel", r: "mythic", pt: "3/3", rules: "Flying. Whenever Eirdu enters or attacks, create a 1/1 white Soldier.", qty: 1 },
  { n: "Eject", c: "1U", col: "U", t: "Instant", r: "common", rules: "Counter target spell unless its controller pays {3}.", qty: 4 },
  { n: "Elder Auntie", c: "3BG", col: "M", t: "Legendary Creature — Hag", r: "rare", pt: "4/4", rules: "Deathtouch. When this enters, each opponent sacrifices a creature.", qty: 1 },
  { n: "Eldamri's Stone", c: "2", col: "C", t: "Artifact", r: "uncommon", rules: "{T}: Add {C}{C}.", qty: 1 },

  // Black
  { n: "Caterhoof Behemoth", c: "4BB", col: "B", t: "Creature — Beast", r: "mythic", pt: "5/5", rules: "Haste. When Caterhoof Behemoth enters, creatures you control gain trample and get +X/+X until end of turn, where X is the number of creatures you control.", qty: 2, price: 42.00, foil: 82.00 },
  { n: "Creepwood Safewright", c: "1G", col: "G", t: "Creature — Dryad Scout", r: "common", pt: "1/2", rules: "{T}: Add one mana of any color.", qty: 2 },
  { n: "Creeping Crystal Coating", c: "2U", col: "U", t: "Enchantment — Aura", r: "uncommon", rules: "Enchanted creature gets -2/-0 and can't attack.", qty: 1 },
  { n: "Creeping Peeper", c: "1B", col: "B", t: "Creature — Horror", r: "common", pt: "2/1", rules: "When this dies, each opponent loses 1 life.", qty: 2 },
  { n: "Creeping Renaissance", c: "2GG", col: "G", t: "Sorcery", r: "rare", rules: "Choose a permanent type. Return all cards of the chosen type from your graveyard to your hand.", qty: 1 },
  { n: "Crescent Island Temple", c: "", col: "land", t: "Land", r: "rare", rules: "{T}: Add {U} or {B}.", qty: 1 },
  { n: "Crib Swap", c: "2W", col: "W", t: "Tribal Instant — Shapeshifter", r: "uncommon", rules: "Exile target creature. Its controller creates a 1/1 colorless Shapeshifter Changeling token.", qty: 2 },
  { n: "Crippling Fear", c: "3BB", col: "B", t: "Sorcery", r: "rare", rules: "Choose a creature type. All creatures other than creatures of the chosen type get -3/-3 until end of turn.", qty: 1 },
  { n: "Crossroads Watcher", c: "2W", col: "W", t: "Creature — Human Scout", r: "common", pt: "2/2", rules: "When this enters, scry 1.", qty: 3 },
  { n: "Cruel Administrator", c: "3B", col: "B", t: "Creature — Vampire", r: "uncommon", pt: "3/2", rules: "When this dies, target opponent discards a card.", qty: 2 },
  { n: "Cruel Truths", c: "3B", col: "B", t: "Sorcery", r: "rare", rules: "Draw three cards. You lose 3 life.", qty: 1 },

  // Green
  { n: "Floodplain Drowner", c: "1U", col: "U", t: "Creature — Merfolk", r: "common", pt: "1/3", rules: "Flash.", qty: 2 },
  { n: "Flopsie, Bumi's Companion", c: "2G", col: "G", t: "Legendary Creature — Beast", r: "rare", pt: "3/3", rules: "Trample. Whenever Flopsie attacks, put a +1/+1 counter on it.", qty: 2 },
  { n: "Flopsie, Bumi's Buddy", c: "1G", col: "G", t: "Legendary Creature — Beast", r: "mythic", pt: "2/2", rules: "Flopsie gets +1/+1 for each other Beast you control.", qty: 1 },
  { n: "Flow of Knowledge", c: "3U", col: "U", t: "Sorcery", r: "uncommon", rules: "Draw X cards, where X is the number of instants and sorceries in your graveyard.", qty: 1 },
  { n: "Flusterstorm", c: "U", col: "U", t: "Instant", r: "mythic", rules: "Counter target instant or sorcery spell unless its controller pays {1}. Storm.", qty: 1 },
  { n: "Flying Dolphin-Fish", c: "2U", col: "U", t: "Creature — Fish", r: "common", pt: "2/3", rules: "Flying.", qty: 1 },
  { n: "Focus Fire", c: "2R", col: "R", t: "Sorcery", r: "uncommon", rules: "Target creature you control deals damage equal to its power to any target.", qty: 2 },
  { n: "Foggy Swamp Hunters", c: "1B", col: "B", t: "Creature — Human Warrior", r: "common", pt: "2/1", rules: "Menace.", qty: 3 },
  { n: "Foggy Swamp Spirit", c: "2B", col: "B", t: "Creature — Spirit", r: "uncommon", pt: "2/2", rules: "Flying. When this dies, return it to your hand.", qty: 2 },
  { n: "Foggy Swamp Vinebenders", c: "3G", col: "G", t: "Creature — Human Druid", r: "common", pt: "3/3", rules: "{T}: Add {G}{G}.", qty: 1 },
  { n: "Folk Hero", c: "1W", col: "W", t: "Creature — Human Soldier", r: "uncommon", pt: "1/2", rules: "Whenever another creature enters, put a +1/+1 counter on Folk Hero.", qty: 2 },
  { n: "Font of Return", c: "1B", col: "B", t: "Enchantment", r: "uncommon", rules: "Sacrifice Font of Return: Return up to three target creature cards from your graveyard to your hand. Lose 3 life.", qty: 1 },
  { n: "Foraging Wickermaw", c: "2G", col: "G", t: "Creature — Plant Beast", r: "uncommon", pt: "3/2", rules: "Reach. Whenever this deals combat damage, gain 1 life.", qty: 2 },
  { n: "Forecasting Fortunes", c: "1U", col: "U", t: "Sorcery", r: "common", rules: "Scry 3.", qty: 3 },
  { n: "Forgotten Creation", c: "2U", col: "U", t: "Creature — Horror", r: "rare", pt: "3/3", rules: "At the beginning of your upkeep, you may discard your hand. If you do, draw four cards.", qty: 1 },

  // Red
  { n: "Kyoshi Island Plaza", c: "", col: "land", t: "Land", r: "rare", rules: "{T}: Add {W} or {U}.", qty: 4 },
  { n: "Kyoshi Warrior Exemplar", c: "2W", col: "W", t: "Creature — Human Warrior", r: "uncommon", pt: "3/3", rules: "Vigilance. When this enters, other Warriors you control get +1/+1 until end of turn.", qty: 2 },
  { n: "Kyoshi Warrior Guardian", c: "3W", col: "W", t: "Creature — Human Warrior Ally", r: "rare", pt: "3/4", rules: "Defender. Kyoshi Warriors you control have vigilance and indestructible.", qty: 1 },
  { n: "Kyoshi Warrior Guard", c: "1W", col: "W", t: "Creature — Human Warrior", r: "common", pt: "2/2", rules: "First strike.", qty: 3 },
  { n: "Kyoshi Warriors", c: "2W", col: "W", t: "Creature — Human Warrior", r: "uncommon", pt: "2/3", rules: "Whenever Kyoshi Warriors attack, create a 1/1 Warrior token.", qty: 3 },
  { n: "Laboratory Maniac", c: "2U", col: "U", t: "Creature — Human Wizard", r: "rare", pt: "2/2", rules: "If you would draw a card while your library has no cards, you win the game instead.", qty: 1 },
  { n: "Laela, the Blade Reforged", c: "2WR", col: "M", t: "Legendary Creature — Human Samurai", r: "mythic", pt: "3/3", rules: "Double strike. Whenever Laela deals combat damage to a player, draw a card.", qty: 1 },
  { n: "Lagorin, Soul of Alacria", c: "3G", col: "G", t: "Legendary Creature — Elemental", r: "mythic", pt: "4/4", rules: "Haste. Other creatures you control have haste.", qty: 1 },
  { n: "Lancers en-Kor", c: "1W", col: "W", t: "Creature — Kor Knight", r: "uncommon", pt: "2/2", rules: "{0}: Target creature you control gets +0/-1 until end of turn. Prevent the next 1 damage to a Kor creature.", qty: 2 },
  { n: "Larder Zombie", c: "1B", col: "B", t: "Creature — Zombie", r: "common", pt: "2/1", rules: "When this dies, mill 2.", qty: 2 },
  { n: "Larval Scoutleader", c: "2G", col: "G", t: "Creature — Insect Scout", r: "uncommon", pt: "2/3", rules: "Whenever this attacks, each other attacking creature gets +1/+0.", qty: 2 },
  { n: "Lashwhip Predator", c: "3B", col: "B", t: "Creature — Hound", r: "common", pt: "3/3", rules: "Menace.", qty: 1 },
  { n: "Lasting Tartfire", c: "1R", col: "R", t: "Instant", r: "common", rules: "Deals 2 damage to any target. Flashback {3}{R}.", qty: 3 },
  { n: "Lathril, Blade of the Elves", c: "1BG", col: "M", t: "Legendary Creature — Elf Noble", r: "rare", pt: "3/3", rules: "Menace. Whenever Lathril deals combat damage, create that many 1/1 Elf tokens.", qty: 1 },
  { n: "Laughing Mad", c: "2R", col: "R", t: "Creature — Goblin", r: "uncommon", pt: "2/2", rules: "Haste. Whenever this attacks alone, it deals 2 damage to target creature defending player controls.", qty: 2 },

  // More — right columns
  { n: "Qutrub Forayer", c: "3B", col: "B", t: "Creature — Zombie", r: "common", pt: "2/4", rules: "", qty: 2 },
  { n: "Rabaroo Troop", c: "2G", col: "G", t: "Creature — Kangaroo", r: "uncommon", pt: "2/2", rules: "Whenever this attacks, create a 1/1 Kangaroo token.", qty: 2 },
  { n: "Rabid Bite", c: "1G", col: "G", t: "Sorcery", r: "common", rules: "Target creature you control fights target creature you don't control.", qty: 3 },
  { n: "Rabid Bloodsucker", c: "3R", col: "R", t: "Creature — Vampire", r: "uncommon", pt: "3/2", rules: "Haste, lifelink.", qty: 1 },
  { n: "Racers' Scoreboard", c: "2", col: "C", t: "Artifact", r: "rare", rules: "Whenever a creature attacks, put a speed counter on it. {T}: Draw a card for each creature with a speed counter.", qty: 1 },
  { n: "Radiant Strike", c: "1W", col: "W", t: "Instant", r: "common", rules: "Target creature gets +1/+1 and gains first strike until end of turn.", qty: 3 },
  { n: "Ragged Playmate", c: "2B", col: "B", t: "Creature — Horror", r: "common", pt: "3/2", rules: "Menace.", qty: 2 },
  { n: "Ragost, Deft Gastronaut", c: "1GU", col: "M", t: "Legendary Creature — Human Chef", r: "mythic", pt: "2/3", rules: "Whenever a Food token enters, draw a card.", qty: 1 },
  { n: "Rai and the Implicit Maze", c: "3U", col: "U", t: "Enchantment — Saga", r: "rare", rules: "I, II — Scry 2. III — Draw three cards.", qty: 1 },
  { n: "Raise Dead", c: "B", col: "B", t: "Sorcery", r: "common", rules: "Return target creature card from your graveyard to your hand.", qty: 4 },
  { n: "Raise the Alarm", c: "1W", col: "W", t: "Instant", r: "common", rules: "Create two 1/1 white Soldier tokens.", qty: 3 },
  { n: "Rakish Crew", c: "2R", col: "R", t: "Creature — Pirate", r: "uncommon", pt: "3/2", rules: "Menace. Whenever this attacks, treasure.", qty: 2 },
  { n: "Rakshasa's Bargain", c: "2B", col: "B", t: "Sorcery", r: "rare", rules: "Draw two cards. Lose 2 life. You may play an additional land this turn.", qty: 1 },
  { n: "Rally the Monastery", c: "3W", col: "W", t: "Sorcery", r: "uncommon", rules: "Create three 1/1 white Monk tokens.", qty: 1 },
  { n: "Rampaging Baloths", c: "4GG", col: "G", t: "Creature — Beast", r: "rare", pt: "6/6", rules: "Trample. Landfall — Whenever a land you control enters, create a 4/4 green Beast token.", qty: 1 },

  // Furthest right
  { n: "Sun-Dappled Celebrant", c: "1W", col: "W", t: "Creature — Human Cleric", r: "common", pt: "2/1", rules: "Lifelink.", qty: 2 },
  { n: "Sunbringer's Touch", c: "3GG", col: "G", t: "Sorcery", r: "rare", rules: "Put three +1/+1 counters on target creature.", qty: 1 },
  { n: "Sunderflock", c: "2W", col: "W", t: "Creature — Bird", r: "common", pt: "2/2", rules: "Flying.", qty: 2 },
  { n: "Sundial, Dawn Tyrant", c: "3WW", col: "W", t: "Legendary Creature — Dragon", r: "mythic", pt: "5/5", rules: "Flying, vigilance. At the beginning of your upkeep, each opponent exiles a card from their hand.", qty: 1 },
  { n: "Sunpearl Kirin", c: "2W", col: "W", t: "Creature — Spirit", r: "uncommon", pt: "2/3", rules: "Flying. Whenever you cast a noncreature spell, Sunpearl Kirin gets +1/+0 until end of turn.", qty: 2 },
  { n: "Sunset Saboteur", c: "3R", col: "R", t: "Creature — Human Rogue", r: "common", pt: "3/2", rules: "Whenever this deals combat damage, sacrifice an artifact. If you do, deal 2 damage to any target.", qty: 1 },
  { n: "Sunset Strikemaster", c: "2R", col: "R", t: "Creature — Human Monk", r: "uncommon", pt: "3/2", rules: "First strike, prowess.", qty: 2 },
  { n: "Sunstar Chaplain", c: "1W", col: "W", t: "Creature — Human Cleric", r: "common", pt: "1/3", rules: "Lifelink.", qty: 3 },
  { n: "Sunstar Expansionist", c: "2W", col: "W", t: "Creature — Human Soldier", r: "uncommon", pt: "2/3", rules: "Whenever this attacks, create a 1/1 Soldier token.", qty: 2 },
  { n: "Sunstar Lightsmith", c: "3W", col: "W", t: "Creature — Human Artificer", r: "rare", pt: "3/3", rules: "When this enters, create a Blinkmoth token.", qty: 1 },
  { n: "Suplex", c: "1R", col: "R", t: "Instant", r: "uncommon", rules: "Target creature you control fights target creature. If it wins, untap it.", qty: 2 },
  { n: "Suppression Ray // Bind the Moon", c: "2WU", col: "M", t: "Sorcery // Sorcery", r: "rare", rules: "Tap all creatures target player controls. // Counter target spell.", qty: 1 },
  { n: "Sure Strike", c: "1R", col: "R", t: "Instant", r: "common", rules: "Target creature gets +3/+0 and gains first strike until end of turn.", qty: 3 },
  { n: "Surgical Suite", c: "", col: "land", t: "Land", r: "rare", rules: "Enters tapped. {T}: Add one mana of any color.", qty: 1 },
  { n: "Surly Farrier", c: "2R", col: "R", t: "Creature — Dwarf", r: "common", pt: "3/2", rules: "Trample.", qty: 2 },
];

// Build full list with randomized set/numbers
const CARDS = CARD_POOL.map((c, i) => ({
  ...c,
  set: SETS[i % SETS.length].code,
  num: String(Math.floor(Math.random() * 400) + 1).padStart(3, '0'),
  cmc: (c.c.match(/[WUBRGC]/g) || []).length + parseInt((c.c.match(/^\d+/) || ['0'])[0], 10),
  pips: (c.c.match(/[WUBRG]/g) || []),
}));

window.VK_DATA = { SETS, CARDS };
