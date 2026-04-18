// Vaultkeeper components
const { useState, useEffect, useRef, useMemo } = React;

const VK_SETS = window.VK_DATA.SETS;
const VK_CARDS = window.VK_DATA.CARDS;

// ============================================================
// Shared bits
// ============================================================

function Mark() {
  return (
    <span className="vk-mark" style={{ textAlign: "left", alignItems: "center", justifyContent: "flex-start", margin: "5px 0px 0px" }}>
      <span className="vk-mark-icon" style={{ margin: "0px 0px 3px" }} />
      Vaultkeeper
    </span>);

}

function Pips({ pips }) {
  if (!pips || !pips.length) return null;
  return (
    <span className="pips">
      {pips.map((p, i) => <span key={i} className={"pip " + p.toLowerCase()} />)}
    </span>);

}

function StripCard({ card, mode, selected, onClick, onHover, onLeave }) {
  if (mode === 'B') {
    // "Top of card" mode — show cropped card art (like looking at the top slice of a physical card)
    const artGradients = {
      W: 'linear-gradient(180deg, #d4c68a 0%, #8a8264 55%, #3a3428 100%)',
      U: 'linear-gradient(180deg, #6a9acf 0%, #2e5680 55%, #14253a 100%)',
      B: 'linear-gradient(180deg, #5a4a55 0%, #2d2730 55%, #0c0a0e 100%)',
      R: 'linear-gradient(180deg, #d47a5e 0%, #8a3a2e 55%, #2a120e 100%)',
      G: 'linear-gradient(180deg, #6ba474 0%, #2e5c3a 55%, #0e1e14 100%)',
      C: 'linear-gradient(180deg, #8a8a92 0%, #3a3a42 55%, #12121a 100%)',
      M: 'linear-gradient(180deg, #c9a04a 0%, #8a6c2e 55%, #2a1e10 100%)',
      land: 'linear-gradient(180deg, #a88868 0%, #5a4a3a 55%, #1e1612 100%)'
    };
    return (
      <div
        className={"vk-strip vk-strip-art" + (selected ? " selected" : "")}
        data-color={card.col}
        onClick={onClick}
        onMouseEnter={onHover}
        onMouseLeave={onLeave}
        style={{
          background: `
            repeating-linear-gradient(135deg, rgba(0,0,0,0.12) 0 1px, transparent 1px 6px),
            repeating-linear-gradient(45deg, rgba(255,255,255,0.05) 0 1px, transparent 1px 8px),
            ${artGradients[card.col] || artGradients.C}
          `
        }}>
        
        <span className="count" style={{ background: 'rgba(0,0,0,0.5)' }}>{card.qty}</span>
        <span className="name" style={{
          color: '#fff',
          textShadow: '0 1px 2px rgba(0,0,0,0.95), 0 0 8px rgba(0,0,0,0.6)',
          fontFamily: 'var(--font-display, var(--font-serif))',
          fontSize: '12px',
          fontWeight: 500,
          letterSpacing: '-0.005em'
        }}>
          {card.n}
        </span>
      </div>);

  }
  return (
    <div
      className={"vk-strip" + (selected ? " selected" : "")}
      data-color={card.col}
      onClick={onClick}
      onMouseEnter={onHover}
      onMouseLeave={onLeave}>
      
      <span className="count">{card.qty}</span>
      <span className="name">{card.n}</span>
      <Pips pips={card.pips} />
    </div>);

}

// ============================================================
// Topbar
// ============================================================

// ============================================================
// Query state — shared by filter chips + syntax search
// ============================================================

const COLOR_OPTS = [
{ k: '', n: 'Any color' },
{ k: 'w', n: 'White' },
{ k: 'u', n: 'Blue' },
{ k: 'b', n: 'Black' },
{ k: 'r', n: 'Red' },
{ k: 'g', n: 'Green' },
{ k: 'c', n: 'Colorless' },
{ k: 'm', n: 'Multicolor' }];

const TYPE_OPTS = [
{ k: '', n: 'Any type' },
{ k: 'creature', n: 'Creature' },
{ k: 'instant', n: 'Instant' },
{ k: 'sorcery', n: 'Sorcery' },
{ k: 'artifact', n: 'Artifact' },
{ k: 'enchantment', n: 'Enchantment' },
{ k: 'planeswalker', n: 'Planeswalker' },
{ k: 'land', n: 'Land' }];

const RARITY_OPTS = [
{ k: '', n: 'Any rarity' },
{ k: 'common', n: 'Common' },
{ k: 'uncommon', n: 'Uncommon' },
{ k: 'rare', n: 'Rare' },
{ k: 'mythic', n: 'Mythic' }];

const SORT_OPTS = [
{ k: 'name', n: 'Name' },
{ k: 'cmc', n: 'Mana value' },
{ k: 'rarity', n: 'Rarity' },
{ k: 'set', n: 'Set' },
{ k: 'edhrec', n: 'EDHREC rank' },
{ k: 'price', n: 'Price' },
{ k: 'added', n: 'Recently added' }];

// Parse query into tokens, extract known operator values, and leave the rest as "free" text
function parseQuery(q) {
  const tokens = (q || '').split(/\s+/).filter(Boolean);
  const state = { color: '', type: '', rarity: '', set: '', sort: 'name', free: [] };
  for (const tok of tokens) {
    const m = tok.match(/^(\w+)(:|>=|<=|>|<|=)(.+)$/);
    if (!m) {state.free.push(tok);continue;}
    const [, key, oper, val] = m;
    const low = val.toLowerCase();
    if ((key === 'c' || key === 'color' || key === 'id') && oper === ':') {
      state.color = low;
    } else if ((key === 't' || key === 'type') && oper === ':') {
      state.type = low;
    } else if ((key === 'r' || key === 'rarity') && oper === ':') {
      state.rarity = low;
    } else if ((key === 'set' || key === 's' || key === 'e') && oper === ':') {
      state.set = low;
    } else if ((key === 'sort' || key === 'order') && oper === ':') {
      state.sort = low;
    } else {
      state.free.push(tok);
    }
  }
  return state;
}

// Serialize state back into a normalized query string. Stable order: free text, color, type, rarity, set, sort.
function serializeQuery(state) {
  const parts = [];
  if (state.free && state.free.length) parts.push(...state.free);
  if (state.color) parts.push(`c:${state.color}`);
  if (state.type) parts.push(`t:${state.type}`);
  if (state.rarity) parts.push(`r:${state.rarity}`);
  if (state.set) parts.push(`set:${state.set}`);
  if (state.sort && state.sort !== 'name') parts.push(`sort:${state.sort}`);
  return parts.join(' ');
}

// ============================================================
// Topbar
// ============================================================

function TopBar({ onTweaks, sidebarCollapsed, onToggleSidebar }) {
  // Single source of truth: query string. Chips derive from parseQuery.
  const [query, setQuery] = useState('c:r t:creature sort:edhrec');
  const parsed = parseQuery(query);

  // When a chip changes a filter, re-serialize the query string.
  const setFilter = (key, val) => {
    const next = { ...parsed, [key]: val };
    setQuery(serializeQuery(next));
  };

  return (
    <>
      <div className="vk-topbar-brand" style={{ textAlign: "left" }}>
        <Mark />
        <button
          className="vk-sidebar-collapse"
          onClick={onToggleSidebar}
          title={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
          aria-label={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}>
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
            <rect x="1.5" y="2" width="11" height="10" rx="1.5" />
            <line x1="5.5" y1="2" x2="5.5" y2="12" />
            {sidebarCollapsed ?
            <path d="M8 5l2 2-2 2" /> :
            <path d="M10 5l-2 2 2 2" />
            }
          </svg>
        </button>
      </div>
      <div className="vk-topbar-center">
        <FilterChip
          label="Color"
          value={parsed.color}
          options={COLOR_OPTS}
          onChange={(v) => setFilter('color', v)}
          tokenClass="tok-c" />

        <FilterChip
          label="Type"
          value={parsed.type}
          options={TYPE_OPTS}
          onChange={(v) => setFilter('type', v)}
          tokenClass="tok-t" />

        <FilterChip
          label="Rarity"
          value={parsed.rarity}
          options={RARITY_OPTS}
          onChange={(v) => setFilter('rarity', v)}
          tokenClass="tok-r" />

        <FilterChip
          label="Set"
          value={parsed.set}
          options={[{ k: '', n: 'Any set' }, ...VK_SETS.map((s) => ({ k: s.code.toLowerCase(), n: s.name }))]}
          onChange={(v) => setFilter('set', v)}
          tokenClass="tok-set" />

        <SyntaxSearch query={query} setQuery={setQuery} />
      </div>
      <div className="vk-topbar-right">
        <FilterChip
          label="Sort"
          value={parsed.sort}
          options={SORT_OPTS}
          onChange={(v) => setFilter('sort', v)}
          tokenClass="tok-sort"
          align="right" />

        <button className="vk-chip" style={{ width: 32, padding: 0, justifyContent: 'center' }} onClick={onTweaks} title="Display options">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><circle cx="6" cy="2.5" r="1" /><circle cx="6" cy="6" r="1" /><circle cx="6" cy="9.5" r="1" /></svg>
        </button>
        <button className="vk-btn vk-btn-primary">Select</button>
      </div>
    </>);

}

function FilterChip({ label, value, options, onChange, tokenClass, align }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {if (ref.current && !ref.current.contains(e.target)) setOpen(false);};
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [open]);

  const selected = options.find((o) => o.k === value);
  const displayLabel = selected && selected.k ? selected.n : label;
  const active = !!(selected && selected.k);

  return (
    <div className="vk-chip-wrap" ref={ref}>
      <button
        className={"vk-chip" + (active ? ' active' : '') + (open ? ' open' : '')}
        onClick={() => setOpen((o) => !o)}>

        {active && <span className={"tok-dot " + (tokenClass || '')} />}
        {displayLabel} <span className="caret">▼</span>
      </button>
      {open &&
      <div className={"vk-chip-menu" + (align === 'right' ? ' right' : '')}>
          {options.map((o) =>
        <button
          key={o.k || '__none'}
          className={"vk-chip-menu-item" + (o.k === value ? ' active' : '')}
          onClick={() => {onChange(o.k);setOpen(false);}}>

              <span className="k">{o.n}</span>
              {o.k && <span className="tok-hint">{label === 'Sort' ? 'sort:' : label === 'Color' ? 'c:' : label === 'Type' ? 't:' : label === 'Rarity' ? 'r:' : 'set:'}{o.k}</span>}
            </button>
        )}
        </div>
      }
    </div>);

}

function SyntaxSearch({ query, setQuery }) {
  const [focused, setFocused] = useState(false);

  const tokens = query.split(/(\s+)/).map((part, i) => {
    if (/^\s+$/.test(part)) return { kind: 'space', text: part, i };
    const m = part.match(/^(-?)(\w+)(:|>=|<=|>|<|=|!=)(.+)$/);
    if (m) return { kind: 'op', neg: m[1], key: m[2], oper: m[3], val: m[4], text: part, i };
    if (part) return { kind: 'text', text: part, i };
    return { kind: 'empty', text: '', i };
  });

  return (
    <div className={"vk-syntax-search" + (focused ? " focused" : "")}>
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.5" style={{ color: 'var(--vk-ink-3)', flexShrink: 0 }}>
        <circle cx="7" cy="7" r="5" />
        <path d="m11 11 3 3" strokeLinecap="round" />
      </svg>
      <div className="vk-syntax-field">
        <div className="vk-syntax-tokens" aria-hidden="true">
          {tokens.map((t, idx) => {
            if (t.kind === 'space') return <span key={idx} className="sp">{t.text}</span>;
            if (t.kind === 'op') return (
              <span key={idx} className={"tok tok-" + t.key}>
                {t.neg && <span className="tok-neg">-</span>}
                <span className="tok-key">{t.key}</span>
                <span className="tok-op">{t.oper}</span>
                <span className="tok-val">{t.val}</span>
              </span>);

            return <span key={idx} className="tok tok-text">{t.text}</span>;
          })}
        </div>
        <input
          className="vk-syntax-input"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          spellCheck={false}
          placeholder="Syntax: c:red t:creature cmc<=3 r:mythic sort:edhrec…" />

      </div>
      <button className="vk-syntax-help" title="Syntax reference">
        <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, fontWeight: 600, letterSpacing: '0.06em' }}>?</span>
      </button>
      <span className="kbd" style={{ marginLeft: 2 }}>⌘K</span>
    </div>);

}

// ============================================================
// Sidebar
// ============================================================

function SetIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5">
      <rect x="2" y="2" width="10" height="10" rx="1" />
      <path d="M2 6h10M6 2v10" opacity="0.4" />
    </svg>);

}

function Sidebar({ activeSet, onSelectSet, mode, setMode, collapsed }) {
  const [openGroups, setOpenGroups] = useState({ tower: true, sealed: false });
  const toggleGroup = (k) => setOpenGroups((g) => ({ ...g, [k]: !g[k] }));

  // Tower group holds all 8 sets (3,170 cards)
  // Sealed contains mixed pools
  return (
    <aside className={"vk-sidebar" + (collapsed ? " collapsed" : "")}>
      <div className="vk-sidebar-header">
        <h3>Collections</h3>
        <div className="vk-sidebar-toggle" style={{ width: "50px", fontSize: "11px" }}>
          <button className={mode === 'A' ? 'active' : ''} onClick={() => setMode && setMode('A')}>A</button>
          <button className={mode === 'B' ? 'active' : ''} onClick={() => setMode && setMode('B')} style={{ textAlign: "center" }}>B</button>
        </div>
      </div>
      <nav className="vk-sidebar-list">
        <button
          className={"vk-sidebar-item vk-sidebar-item-top" + (activeSet === 'ALL' ? ' active' : '')}
          onClick={() => onSelectSet('ALL')}>
          
          <span className="set-sym"><SetIcon /></span>
          <span className="label">All Cards</span>
          <span className="num">3,170</span>
        </button>

        <SidebarGroup
          name="Tower"
          total="3,170"
          open={openGroups.tower}
          onToggle={() => toggleGroup('tower')}>
          
          {VK_SETS.map((s) =>
          <button
            key={s.code}
            className={"vk-sidebar-item vk-sidebar-item-nested" + (activeSet === s.code ? ' active' : '')}
            onClick={() => onSelectSet(s.code)}>
            
              <span className="drag" title="Drag">
                <svg width="8" height="12" viewBox="0 0 8 12" fill="currentColor"><circle cx="2" cy="2" r="1" /><circle cx="6" cy="2" r="1" /><circle cx="2" cy="6" r="1" /><circle cx="6" cy="6" r="1" /><circle cx="2" cy="10" r="1" /><circle cx="6" cy="10" r="1" /></svg>
              </span>
              <span className="set-sym"><SetIcon /></span>
              <span className="label">{s.name}</span>
              <span className="num">{s.count}</span>
              <span className="edit" title="Rename">
                <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="1.5"><path d="M8 2l2 2-6 6-2.5.5.5-2.5z" strokeLinejoin="round" /></svg>
              </span>
            </button>
          )}
        </SidebarGroup>

        <SidebarGroup
          name="Sealed pools"
          total="826"
          open={openGroups.sealed}
          onToggle={() => toggleGroup('sealed')}>
          
          <button className="vk-sidebar-item vk-sidebar-item-nested">
            <span className="drag"><svg width="8" height="12" viewBox="0 0 8 12" fill="currentColor"><circle cx="2" cy="2" r="1" /><circle cx="6" cy="2" r="1" /><circle cx="2" cy="6" r="1" /><circle cx="6" cy="6" r="1" /><circle cx="2" cy="10" r="1" /><circle cx="6" cy="10" r="1" /></svg></span>
            <span className="set-sym"><SetIcon /></span>
            <span className="label">Draft Leftovers</span>
            <span className="num">412</span>
          </button>
          <button className="vk-sidebar-item vk-sidebar-item-nested">
            <span className="drag"><svg width="8" height="12" viewBox="0 0 8 12" fill="currentColor"><circle cx="2" cy="2" r="1" /><circle cx="6" cy="2" r="1" /><circle cx="2" cy="6" r="1" /><circle cx="6" cy="6" r="1" /><circle cx="2" cy="10" r="1" /><circle cx="6" cy="10" r="1" /></svg></span>
            <span className="set-sym"><SetIcon /></span>
            <span className="label">Trade Binder</span>
            <span className="num">414</span>
          </button>
        </SidebarGroup>

        <div style={{ height: 80 }} />
      </nav>
      <div className="vk-sidebar-footer">
        <div className="row">
          <button className="mini">+ Location</button>
          <button className="mini">+ Group</button>
        </div>
        <button className="import-btn">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M6 2v6M3 5l3 3 3-3M2 10h8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          Import CSV
        </button>
      </div>
    </aside>);

}

function SidebarGroup({ name, total, open, onToggle, children }) {
  return (
    <div className={"vk-sidebar-group" + (open ? ' open' : '')}>
      <button className="vk-sidebar-group-header" onClick={onToggle}>
        <span className="chev" style={{ transform: open ? 'rotate(90deg)' : 'rotate(0deg)' }}>
          <svg width="10" height="10" viewBox="0 0 10 10" fill="currentColor"><path d="M3 2l4 3-4 3z" /></svg>
        </span>
        <span className="label">{name}</span>
        <span className="num">{total}</span>
      </button>
      {open && <div className="vk-sidebar-group-body">{children}</div>}
    </div>);

}

// ============================================================
// Stats bar (new — gives life to the empty space)
// ============================================================

function StatsBar({ selected }) {
  return (
    <div className="vk-stats-bar">
      <div className="stat">
        <span className="k">Unique</span>
        <span className="v">3,170</span>
      </div>
      <div className="stat">
        <span className="k">Total</span>
        <span className="v">5,842</span>
      </div>
      <div className="stat">
        <span className="k">Est. Value <span style={{
            fontFamily: 'var(--font-mono)', fontSize: 8, padding: '1px 5px',
            background: 'color-mix(in oklab, var(--vk-gold) 18%, transparent)',
            color: 'var(--vk-gold)', borderRadius: 3, letterSpacing: '0.08em', marginLeft: 4, borderStyle: "none", borderColor: "rgba(240, 195, 92, 0)", opacity: "0"
          }}></span></span>
        <span className="v">
          €11,462
          <span style={{
            fontFamily: 'var(--font-mono)', fontSize: 11, fontWeight: 500,
            color: '#6aa86a', marginLeft: 8, letterSpacing: 0
          }}></span>
        </span>
      </div>
      <div className="stat">
        <span className="k">Last Added</span>
        <span className="v" style={{ fontSize: 13, fontFamily: 'var(--font-sans)', color: 'var(--vk-ink-2)' }}>2 days ago</span>
      </div>
      <div className="grow" />
      <div className="stat" style={{ alignItems: 'flex-end' }}>
        <span className="k">Showing</span>
        <span className="v" style={{ fontSize: 13, fontFamily: 'var(--font-sans)', color: 'var(--vk-ink-2)' }}>
          1–120 of 3,170
        </span>
      </div>
    </div>);

}

// ============================================================
// Card list
// ============================================================

function CardList({ mode, selectedIdx, onSelect, onHover, onLeave }) {
  return (
    <div className="vk-cardlist-wrap">
      <div className={"vk-cardlist" + (mode === 'B' ? ' vk-cardlist-B' : '')}>
        {VK_CARDS.map((card, i) =>
        <StripCard
          key={i}
          card={card}
          mode={mode}
          selected={selectedIdx === i}
          onClick={(e) => onSelect(i, e)}
          onHover={(e) => onHover && onHover(i, e)}
          onLeave={onLeave} />

        )}
      </div>
    </div>);

}

// ============================================================
// Detail sidebar
// ============================================================

function DetailSidebar({ card, onClose }) {
  if (!card) return null;
  return (
    <aside className="vk-detail">
      <div className="vk-detail-header">
        <button className="close" onClick={onClose} aria-label="Close">✕</button>
      </div>
      <div className="vk-detail-body">
        {/* Art placeholder using diagonal stripes */}
        <div className="vk-detail-art" style={{ background: `
            linear-gradient(135deg, #3d2418 0%, #1a1a22 100%),
            repeating-linear-gradient(45deg, rgba(255,255,255,0.03) 0 2px, transparent 2px 12px)
          `,
          backgroundBlendMode: 'overlay',
          position: 'relative'
        }}>
          <div style={{
            position: 'absolute', inset: 0,
            backgroundImage: 'repeating-linear-gradient(45deg, rgba(255,255,255,0.04) 0 2px, transparent 2px 16px)'
          }} />
          <div style={{
            position: 'absolute', bottom: 16, left: 16,
            fontFamily: 'var(--font-mono)', fontSize: 10, color: 'rgba(255,255,255,0.5)',
            letterSpacing: '0.08em'
          }}>
            [ card art — {card.n.toLowerCase().replace(/[^a-z]/g, '-')} ]
          </div>
        </div>

        <h2 className="vk-detail-title">{card.n}</h2>
        <div className="vk-detail-meta-row">
          <span className="set-badge">{card.set} · {card.num}</span>
          <span className="rarity" data-r={card.r}>{card.r}</span>
          <Pips pips={card.pips} />
          {card.c && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--vk-ink-3)' }}>{card.c}</span>}
        </div>
        <div className="vk-detail-type">{card.t}</div>

        <div className="vk-detail-sep" />

        <div className="vk-detail-rules">
          {card.rules.split('. ').filter(Boolean).map((line, i) =>
          <p key={i}>{line.trim().replace(/\.?$/, '.')}</p>
          )}
        </div>

        {card.pt &&
        <div className="vk-detail-pt">
            <div className="pt">{card.pt}</div>
          </div>
        }

        <div className="vk-detail-section">
          <h4>Your Copies</h4>
          <div className="vk-field-row">
            <div className="vk-field">
              <label>Condition</label>
              <div className="input">NM <span className="caret">▼</span></div>
            </div>
            <div className="vk-field">
              <label>Location</label>
              <div className="input" style={{ fontSize: 12 }}>Tarkir Dragonstorm <span className="caret">▼</span></div>
            </div>
          </div>
          <div className="vk-field-row">
            <div className="vk-field">
              <label>Quantity</label>
              <div className="input">{card.qty} <span className="caret">▼</span></div>
            </div>
            <div className="vk-field">
              <label>Foil</label>
              <div className="input">No <span className="caret">▼</span></div>
            </div>
          </div>
        </div>

        <div className="vk-detail-section">
          <h4>Market</h4>
          <div className="vk-price-grid">
            <div className="cell">
              <span className="k">Low</span>
              <span className="v">${(card.price || 12).toFixed(2)}</span>
            </div>
            <div className="cell">
              <span className="k">Market</span>
              <span className="v up">${((card.price || 12) * 1.15).toFixed(2)}</span>
            </div>
            <div className="cell">
              <span className="k">Foil</span>
              <span className="v">${(card.foil || (card.price || 12) * 2.4).toFixed(2)}</span>
            </div>
          </div>
          <div style={{
            marginTop: 8, fontSize: 11, color: 'var(--vk-ink-3)',
            fontFamily: 'var(--font-mono)', letterSpacing: '0.04em'
          }}>

          </div>
        </div>
      </div>
    </aside>);

}

// ============================================================
// Card peek (hover popover)
// ============================================================

function CardPeek({ card, x, y, visible }) {
  if (!card) return null;
  const art = {
    background: `
      linear-gradient(135deg, #3d2418 0%, #1a1a22 60%, #0a0a10 100%)
    `
  };
  return (
    <div className={"vk-peek" + (visible ? " visible" : "")} style={{ left: x, top: y }}>
      <div style={{ ...art, width: '100%', height: '100%', position: 'relative', padding: 12, display: 'flex', flexDirection: 'column' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: '#fff', fontSize: 13, fontWeight: 600 }}>
          <span>{card.n}</span>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, opacity: 0.8 }}>{card.c}</span>
        </div>
        <div style={{
          flex: 1, marginTop: 10, borderRadius: 6,
          background: `
            repeating-linear-gradient(45deg, rgba(255,255,255,0.04) 0 2px, transparent 2px 14px),
            linear-gradient(160deg, #5a3a24, #1a1420)
          `,
          position: 'relative'
        }}>
          <div style={{
            position: 'absolute', bottom: 8, left: 10, right: 10,
            fontFamily: 'var(--font-mono)', fontSize: 9, color: 'rgba(255,255,255,0.5)',
            letterSpacing: '0.06em'
          }}>[ card art ]</div>
        </div>
        <div style={{ marginTop: 8, fontStyle: 'italic', color: '#c8c8d0', fontSize: 11, fontFamily: 'var(--font-serif)' }}>
          {card.t}
        </div>
        <div style={{ marginTop: 6, fontSize: 11, color: '#d8d8e0', lineHeight: 1.35, minHeight: 48 }}>
          {card.rules.slice(0, 120)}{card.rules.length > 120 ? '…' : ''}
        </div>
        {card.pt &&
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 6 }}>
            <div style={{
            fontFamily: 'var(--font-serif)', fontSize: 15, color: '#fff',
            padding: '1px 10px', background: 'rgba(0,0,0,0.4)', borderRadius: 3,
            border: '1px solid rgba(255,255,255,0.1)'
          }}>{card.pt}</div>
          </div>
        }
      </div>
    </div>);

}

// ============================================================
// Collection screen (main app)
// ============================================================

function CollectionScreen({ showDetail, showHover, onTweaks }) {
  const [activeSet, setActiveSet] = useState('TDM');
  const [mode, setMode] = useState('A'); // A = typed names, B = card-top art
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [selectedIdx, setSelectedIdx] = useState(showDetail ? 10 : null); // Caterhoof Behemoth
  const [hoverIdx, setHoverIdx] = useState(showHover ? 40 : null);
  const [hoverPos, setHoverPos] = useState({ x: 0, y: 0 });
  const mainRef = useRef(null);

  const selectedCard = selectedIdx != null ? VK_CARDS[selectedIdx] : null;
  const hoverCard = hoverIdx != null ? VK_CARDS[hoverIdx] : null;

  const handleHover = (i, e) => {
    if (showDetail) return; // don't peek if detail is open
    const rect = e.currentTarget.getBoundingClientRect();
    const main = mainRef.current.getBoundingClientRect();
    // Position peek next to the column (right of the hovered strip)
    // so cards above/below remain visible
    const peekW = 240;
    const gap = 10;
    let x = rect.right - main.left + gap;
    // If the peek would go off-screen right, flip to the left
    if (x + peekW > main.width - 20) {
      x = rect.left - main.left - peekW - gap;
    }
    // Vertically center the peek on the hovered strip
    const peekH = 340;
    let y = rect.top - main.top + rect.height / 2 - peekH / 2;
    // Clamp within main area
    y = Math.max(12, Math.min(y, main.height - peekH - 12));
    setHoverIdx(i);
    setHoverPos({ x, y });
  };

  // For the "demo" hover state, position peek statically next to the strip
  useEffect(() => {
    if (showHover && mainRef.current) {
      const strips = mainRef.current.querySelectorAll('.vk-strip');
      if (strips[hoverIdx]) {
        const rect = strips[hoverIdx].getBoundingClientRect();
        const main = mainRef.current.getBoundingClientRect();
        const peekW = 240,peekH = 340,gap = 10;
        let x = rect.right - main.left + gap;
        if (x + peekW > main.width - 20) {
          x = rect.left - main.left - peekW - gap;
        }
        let y = rect.top - main.top + rect.height / 2 - peekH / 2;
        y = Math.max(12, Math.min(y, main.height - peekH - 12));
        setHoverPos({ x, y });
      }
    }
  }, [showHover, hoverIdx]);

  return (
    <div className="vk-app" data-sidebar={sidebarCollapsed ? "collapsed" : "expanded"} ref={mainRef}>
      <header className="vk-topbar">
        <TopBar onTweaks={onTweaks} sidebarCollapsed={sidebarCollapsed} onToggleSidebar={() => setSidebarCollapsed((c) => !c)} />
      </header>

      <Sidebar activeSet={activeSet} onSelectSet={setActiveSet} mode={mode} setMode={setMode} collapsed={sidebarCollapsed} />

      <main className={"vk-main" + (showDetail ? " with-detail" : "")}>
        <StatsBar />
        <div style={{ display: 'flex', flex: 1, overflow: 'hidden' }}>
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
            <CardList
              mode={mode}
              selectedIdx={selectedIdx}
              onSelect={(i) => setSelectedIdx(selectedIdx === i ? null : i)}
              onHover={handleHover}
              onLeave={() => !showHover && setHoverIdx(null)} />
            
          </div>
          {showDetail && selectedCard &&
          <DetailSidebar card={selectedCard} onClose={() => setSelectedIdx(null)} />
          }
        </div>
      </main>

      {!showDetail &&
      <CardPeek
        card={hoverCard}
        x={hoverPos.x}
        y={hoverPos.y + 56 /* topbar */}
        visible={hoverIdx != null} />

      }
    </div>);

}

// ============================================================
// Login screen
// ============================================================

function LoginScreen() {
  return (
    <div className="vk-login">
      <div className="vk-login-form-side">
        <Mark />
        <div className="vk-login-form">
          <div className="vk-login-eyebrow">The Collector's Archive</div>
          <h1 className="vk-login-title">
            Keep the <em>vault</em> behind the glass.
          </h1>
          <p className="vk-login-sub">
            Catalog, value, and retrieve thousands of cards across every set you've ever sleeved — organized the way a collector thinks.
          </p>

          <div className="vk-login-input">
            <label>Username or Email</label>
            <input type="text" defaultValue="archivist@vaultkeeper.app" />
          </div>
          <div className="vk-login-input">
            <label>Password</label>
            <input type="password" defaultValue="••••••••••••" />
          </div>

          <button className="vk-login-btn">Enter the Vault →</button>

          <div className="vk-login-meta">
            <a href="#">Forgot password?</a>
            <a href="#">Create an account</a>
          </div>
        </div>
        <div className="vk-login-foot">
          <span>VK / v2.4.0</span>
          <span>Est. 2026</span>
        </div>
      </div>

      <div className="vk-login-hero-side">
        <div className="vk-hero-grid" />

        <div className="vk-hero-stack">
          <HeroCardWall />
        </div>

        <div className="vk-hero-bottom">
          <div className="vk-hero-ticker">
            <div className="item"><span className="k">NEWEST SET</span><span className="v">Tarkir Dragonstorm</span></div>
          </div>
          <span>AUTH / SECURE SESSION</span>
        </div>
      </div>
    </div>);

}

function HeroCardWall() {
  // A curated wall of strip-cards + one "hero" real card in the middle
  const leftCol = VK_CARDS.slice(0, 10);
  const rightCol = VK_CARDS.slice(20, 30);
  const bottomCol = VK_CARDS.slice(40, 50);
  const topCol = VK_CARDS.slice(60, 70);

  const stripRow = (cards, y, opacity = 1, delay = 0) =>
  <div style={{
    position: 'absolute', left: 0, right: 0, top: y,
    display: 'flex', gap: 8, padding: '0 40px',
    opacity, transform: 'perspective(800px) rotateX(2deg)'
  }}>
      {cards.map((c, i) =>
    <div key={i} style={{ flex: 1, minWidth: 0 }}>
          <MiniStrip card={c} />
        </div>
    )}
    </div>;


  return (
    <div style={{
      position: 'absolute', inset: 0,
      maskImage: 'radial-gradient(ellipse 100% 80% at center, black 40%, transparent 85%)',
      WebkitMaskImage: 'radial-gradient(ellipse 100% 80% at center, black 40%, transparent 85%)'
    }}>
      {/* Stacked rows going up + down */}
      {stripRow(topCol.slice(0, 6), '8%', 0.35)}
      {stripRow(leftCol.slice(0, 6), '18%', 0.55)}
      {stripRow(rightCol.slice(0, 6), '28%', 0.8)}
      {stripRow(bottomCol.slice(0, 6), '38%', 0.6)}

      {/* Hero card — the real thing */}
      <div style={{
        position: 'absolute',
        left: '50%', top: '52%',
        transform: 'translate(-50%, -50%) perspective(800px) rotateY(-3deg)',
        width: 280, aspectRatio: '63 / 88',
        borderRadius: 14,
        background: `
          linear-gradient(160deg, #5a3a24, #2a1a14 60%, #0a0a10 100%)
        `,
        boxShadow: `
          0 0 0 1px rgba(240, 195, 92, 0.3),
          0 40px 80px rgba(0,0,0,0.7),
          0 20px 40px rgba(240, 195, 92, 0.08)
        `,
        padding: 14,
        display: 'flex', flexDirection: 'column', gap: 10
      }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', color: '#fff' }}>
          <span style={{ fontFamily: 'var(--font-display, var(--font-serif))', fontSize: 16, fontWeight: 500 }}>Ukkima, Stalking Shadow</span>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, opacity: 0.85 }}>4BR</span>
        </div>
        <div style={{
          flex: 1, borderRadius: 8,
          background: `
            repeating-linear-gradient(45deg, rgba(255,255,255,0.04) 0 2px, transparent 2px 16px),
            linear-gradient(160deg, #6b3a3a, #2a1418 60%, #0a0a10 100%)
          `,
          border: '1px solid rgba(0,0,0,0.4)',
          position: 'relative'
        }}>
          <div style={{
            position: 'absolute', bottom: 10, left: 12,
            fontFamily: 'var(--font-mono)', fontSize: 9, color: 'rgba(255,255,255,0.5)',
            letterSpacing: '0.06em'
          }}>[ ukkima-stalking-shadow.jpg ]</div>
        </div>
        <div style={{ fontStyle: 'italic', color: '#c8c8d0', fontSize: 12, fontFamily: 'var(--font-serif)' }}>
          Legendary Creature — Dragon
        </div>
        <div style={{ fontSize: 11.5, color: '#e8e8ec', lineHeight: 1.4 }}>
          Flying. Whenever Ukkima deals combat damage to a player, that player discards a card and you draw a card.
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 2 }}>
          <span style={{ fontFamily: 'var(--font-mono)', fontSize: 10, color: 'rgba(255,255,255,0.4)' }}>TDM · 128</span>
          <div style={{
            fontFamily: 'var(--font-display, var(--font-serif))', fontSize: 18, color: '#fff',
            padding: '1px 12px', background: 'rgba(0,0,0,0.4)', borderRadius: 4,
            border: '1px solid rgba(255,255,255,0.1)'
          }}>4/4</div>
        </div>
      </div>

      {/* More rows below */}
      <div style={{ position: 'absolute', left: 0, right: 0, bottom: '8%' }}>
        {stripRow(bottomCol.slice(0, 6), 0, 0.55)}
      </div>
    </div>);

}

function MiniStrip({ card }) {
  return (
    <div className="vk-strip" data-color={card.col} style={{ height: 22, pointerEvents: 'none' }}>
      <span className="count" style={{ width: 18, fontSize: 10 }}>{card.qty}</span>
      <span className="mana" style={{ width: 12, fontSize: 9 }}>{card.cmc || '—'}</span>
      <span className="name" style={{ fontSize: 11 }}>{card.n}</span>
    </div>);

}

// ============================================================
// Screen switcher + Tweaks
// ============================================================

function ScreenSwitcher({ screen, setScreen }) {
  const items = [
  { k: 'login', n: 'Login' },
  { k: 'collection', n: 'Collection' },
  { k: 'detail', n: 'Details' },
  { k: 'hover', n: 'Peek' }];

  return (
    <div className="vk-screen-switcher">
      {items.map((i) =>
      <button
        key={i.k}
        className={screen === i.k ? 'active' : ''}
        onClick={() => setScreen(i.k)}>
        {i.n}</button>
      )}
    </div>);

}

function Tweaks({ open, aesthetic, setAesthetic, density, setDensity }) {
  return (
    <div className={"vk-tweaks" + (open ? " open" : "")}>
      <h4>Aesthetic Direction</h4>
      <div className="seg" style={{ marginBottom: 14 }}>
        {['editorial', 'moody', 'utility'].map((a) =>
        <button key={a} className={aesthetic === a ? 'active' : ''} onClick={() => setAesthetic(a)}>
            {a[0].toUpperCase() + a.slice(1)}
          </button>
        )}
      </div>
      <h4>Density</h4>
      <div className="seg">
        {['compact', 'default', 'cozy'].map((d) =>
        <button key={d} className={density === d ? 'active' : ''} onClick={() => setDensity(d)}>
            {d[0].toUpperCase() + d.slice(1)}
          </button>
        )}
      </div>
    </div>);

}

// ============================================================
// Root
// ============================================================

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "screen": "login",
  "aesthetic": "editorial",
  "density": "default"
} /*EDITMODE-END*/;

function App() {
  const [screen, setScreen] = useState(() => localStorage.getItem('vk_screen') || TWEAK_DEFAULTS.screen);
  const [aesthetic, setAesthetic] = useState(() => localStorage.getItem('vk_aesthetic') || TWEAK_DEFAULTS.aesthetic);
  const [density, setDensity] = useState(() => localStorage.getItem('vk_density') || TWEAK_DEFAULTS.density);
  const [tweaksOpen, setTweaksOpen] = useState(false);
  const [editMode, setEditMode] = useState(false);

  useEffect(() => {localStorage.setItem('vk_screen', screen);}, [screen]);
  useEffect(() => {localStorage.setItem('vk_aesthetic', aesthetic);}, [aesthetic]);
  useEffect(() => {localStorage.setItem('vk_density', density);}, [density]);

  useEffect(() => {
    document.documentElement.setAttribute('data-aesthetic', aesthetic);
    document.documentElement.setAttribute('data-density', density);
  }, [aesthetic, density]);

  // Tweak-mode protocol
  useEffect(() => {
    const handler = (e) => {
      if (e.data?.type === '__activate_edit_mode') setEditMode(true);
      if (e.data?.type === '__deactivate_edit_mode') setEditMode(false);
    };
    window.addEventListener('message', handler);
    window.parent.postMessage({ type: '__edit_mode_available' }, '*');
    return () => window.removeEventListener('message', handler);
  }, []);

  return (
    <>
      <ScreenSwitcher screen={screen} setScreen={setScreen} />
      {editMode &&
      <Tweaks
        open={true}
        aesthetic={aesthetic} setAesthetic={(v) => {
          setAesthetic(v);
          window.parent.postMessage({ type: '__edit_mode_set_keys', edits: { aesthetic: v } }, '*');
        }}
        density={density} setDensity={(v) => {
          setDensity(v);
          window.parent.postMessage({ type: '__edit_mode_set_keys', edits: { density: v } }, '*');
        }} />

      }

      {screen === 'login' && <LoginScreen />}
      {screen === 'collection' && <CollectionScreen showDetail={false} showHover={false} onTweaks={() => setTweaksOpen((v) => !v)} />}
      {screen === 'detail' && <CollectionScreen showDetail={true} showHover={false} onTweaks={() => setTweaksOpen((v) => !v)} />}
      {screen === 'hover' && <CollectionScreen showDetail={false} showHover={true} onTweaks={() => setTweaksOpen((v) => !v)} />}
    </>);

}

const root = ReactDOM.createRoot(document.getElementById('root'));
root.render(<App />);