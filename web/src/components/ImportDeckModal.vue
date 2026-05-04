<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useCollectionStore } from '../stores/collection'

// `assemble` fires after a successful import when the user pre-ticked
// the "already assembled" toggle. Carries the new deck's id + name so
// the parent can open AssembleDeckModal against it. Closing this modal
// after an emit is the parent's responsibility.
const emit = defineEmits(['close', 'assemble'])
const collection = useCollectionStore()
const router = useRouter()

const tab = ref('url') // 'url' | 'text'
const url = ref('')
const text = ref('')
const name = ref('')
const format = ref('commander')
// Per locked decision 7, the toggle is a SPA-side concern: the import
// POST never receives it. It will eventually open AssembleDeckModal
// against the new deck, but that modal lands in a follow-up branch —
// for now the toggle is captured but no-op.
const assembled = ref(false)

const submitting = ref(false)
const error = ref('')
const result = ref(null)
const firstInput = ref(null)
// Conflict info from a 409 response — when set, the modal shows the
// 3-button "Update / Add as new / Cancel" choice instead of the form.
const conflict = ref(null)

function onKeydown(e) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  nextTick(() => firstInput.value?.focus?.())
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

// Detect source from URL prefix — only for display. Backend does its own
// parsing, but we label the chip so the user has a visible "we recognized
// this" acknowledgement.
const detectedSource = computed(() => {
  const u = url.value.trim().toLowerCase()
  if (!u) return null
  if (u.includes('archidekt.com/decks/')) return 'Archidekt'
  if (u.includes('moxfield.com/decks/')) return 'Moxfield'
  return null
})

const canSubmit = computed(() => {
  if (submitting.value) return false
  if (tab.value === 'url') return !!detectedSource.value
  return !!(text.value.trim() && name.value.trim())
})

// `mode` is sent on every URL import:
//   'auto'   — first attempt; backend bails with 409 if a same-source deck exists.
//   'update' — overwrite the matching existing deck.
//   'create' — force a new deck (intentional duplicate).
async function submit(mode = 'auto') {
  if (!canSubmit.value && mode === 'auto') return
  submitting.value = true
  error.value = ''
  result.value = null
  conflict.value = null
  try {
    const payload = tab.value === 'url'
      ? {
          source: detectedSource.value === 'Archidekt' ? 'archidekt' : 'moxfield',
          url: url.value.trim(),
          mode,
        }
      : {
          source: 'text',
          text: text.value,
          name: name.value.trim(),
          format: format.value,
        }
    result.value = await collection.importDeck(payload)
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.existing) {
      conflict.value = e.response.data.existing
    } else if (e.response?.status === 422) {
      const errs = e.response.data.errors
      error.value = errs
        ? Object.values(errs).flat().join('; ')
        : e.response.data.message || 'Validation failed'
    } else {
      error.value = e.response?.data?.message || 'Import failed'
    }
  } finally {
    submitting.value = false
  }
}

function cancelConflict() {
  conflict.value = null
}

function openDeck() {
  const id = result.value?.deck?.id ?? conflict.value?.id
  emit('close')
  if (id) router.push({ name: 'deck', params: { id } })
}

function requestAssemble() {
  const deck = result.value?.deck
  if (!deck) return
  emit('assemble', { id: deck.id, name: deck.name })
  emit('close')
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div
      class="modal-card"
      role="dialog"
      aria-modal="true"
      aria-labelledby="import-deck-title"
    >
      <h2 id="import-deck-title" class="display">Import Deck</h2>

      <div v-if="!result && !conflict" class="tabs" role="tablist">
        <button
          type="button"
          class="tab"
          :class="{ active: tab === 'url' }"
          role="tab"
          :aria-selected="tab === 'url'"
          @click="tab = 'url'"
        >From URL</button>
        <button
          type="button"
          class="tab"
          :class="{ active: tab === 'text' }"
          role="tab"
          :aria-selected="tab === 'text'"
          @click="tab = 'text'"
        >From text</button>
      </div>

      <div v-if="conflict" class="conflict">
        <p class="conflict-msg">
          You already imported this deck as
          <strong>{{ conflict.name }}</strong>.
        </p>
        <p class="conflict-hint">
          <strong>Update</strong> overwrites the cards, commanders, format and
          description from the source — the deck's group placement and any
          ignored illegalities are kept.
          <strong>Add as new</strong> imports a separate copy.
        </p>
        <div class="actions">
          <button type="button" @click="cancelConflict">Cancel</button>
          <button type="button" @click="submit('create')" :disabled="submitting">Add as new</button>
          <button type="button" class="primary" @click="submit('update')" :disabled="submitting">
            {{ submitting ? 'Updating…' : 'Update' }}
          </button>
        </div>
      </div>

      <form v-else-if="!result" @submit.prevent="submit('auto')">
        <template v-if="tab === 'url'">
          <label class="field">
            <span class="label">Deck URL</span>
            <input
              ref="firstInput"
              name="deck-url"
              autocomplete="off"
              v-model="url"
              type="url"
              placeholder="https://archidekt.com/decks/… or https://moxfield.com/decks/…"
            />
            <span v-if="detectedSource" class="source-chip">{{ detectedSource }} detected</span>
            <span v-else-if="url" class="source-chip warn">Unrecognized URL</span>
          </label>
        </template>

        <template v-else>
          <label class="field">
            <span class="label">Deck name</span>
            <input ref="firstInput" name="deck-name" autocomplete="off" v-model="name" type="text" maxlength="100" />
          </label>
          <label class="field">
            <span class="label">Format</span>
            <select v-model="format" name="format">
              <option value="commander">Commander</option>
              <option value="oathbreaker">Oathbreaker</option>
              <option value="pauper">Pauper</option>
              <option value="standard">Standard</option>
              <option value="modern">Modern</option>
            </select>
          </label>
          <label class="field">
            <span class="label">Decklist</span>
            <textarea
              name="decklist"
              v-model="text"
              class="decklist"
              rows="12"
              placeholder="Commander&#10;1 Atraxa, Praetors' Voice&#10;&#10;Deck&#10;1 Sol Ring&#10;…&#10;&#10;Sideboard&#10;2 Rest in Peace"
            ></textarea>
          </label>
        </template>

        <label class="assembled-row">
          <input
            type="checkbox"
            v-model="assembled"
            name="deck-assembled"
          />
          <span class="assembled-label">
            This deck is already assembled
            <span
              class="assembled-hint"
              tabindex="0"
              role="img"
              aria-label="Tick this if you physically own the cards in this deck. We'll log them in your collection under 'Deck: <name>' so they don't show up in your bulk or binders."
              title="Tick this if you physically own the cards in this deck. We'll log them in your collection under 'Deck: <name>' so they don't show up in your bulk or binders."
            >?</span>
          </span>
        </label>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="actions">
          <button type="button" @click="emit('close')">Cancel</button>
          <button type="submit" class="primary" :disabled="!canSubmit">
            {{ submitting ? 'Importing…' : 'Import' }}
          </button>
        </div>
      </form>

      <div v-else class="result">
        <div class="stats">
          <div class="stat">
            <span class="stat-val">{{ result.imported }}</span>
            <span class="stat-lbl">Imported</span>
          </div>
          <div class="stat">
            <span class="stat-val">{{ result.skipped }}</span>
            <span class="stat-lbl">Skipped</span>
          </div>
          <div class="stat">
            <span class="stat-val">{{ result.warnings?.length || 0 }}</span>
            <span class="stat-lbl">Warnings</span>
          </div>
        </div>

        <ul v-if="result.warnings?.length" class="warnings">
          <li v-for="(w, i) in result.warnings" :key="i">{{ w }}</li>
        </ul>

        <div class="deck-summary">
          Created <strong>{{ result.deck?.name }}</strong>
          <span class="format-badge">{{ result.deck?.format }}</span>
        </div>

        <div class="actions">
          <button type="button" @click="emit('close')">Close</button>
          <button v-if="!assembled" type="button" class="primary" @click="openDeck">Open Deck</button>
          <template v-else>
            <button type="button" @click="openDeck">Open Deck</button>
            <button type="button" class="primary" @click="requestAssemble">Mark as assembled</button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal-card {
  background: var(--bg-1);
  border: 1px solid var(--hairline);
  border-top: 2px solid var(--amber);
  border-radius: 6px;
  width: 520px;
  max-width: calc(100vw - 32px);
  padding: 22px 24px 24px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.modal-card h2 {
  font-size: 20px;
  color: var(--amber);
  margin-bottom: 14px;
}
.tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 14px;
  border-bottom: 1px solid var(--hairline);
}
.tab {
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--ink-50);
  padding: 8px 14px;
  font-size: 13px;
  cursor: pointer;
}
.tab.active {
  color: var(--amber);
  border-bottom-color: var(--amber);
}
.field { display: block; margin-bottom: 14px; position: relative; }
.label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin-bottom: 5px;
}
.decklist {
  font-family: ui-monospace, SFMono-Regular, 'Menlo', monospace;
  font-size: 12px;
  line-height: 1.5;
  resize: vertical;
}
.source-chip {
  display: inline-block;
  margin-top: 4px;
  padding: 2px 8px;
  font-size: 11px;
  letter-spacing: 0.04em;
  border-radius: 999px;
  background: rgba(201, 157, 61, 0.12);
  color: var(--amber);
}
.source-chip.warn { background: rgba(200, 80, 80, 0.15); color: #e88; }
.assembled-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 6px 0 4px;
  font-size: 13px;
  color: var(--ink-100);
  cursor: pointer;
}
.assembled-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.assembled-hint {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--bg-0);
  color: var(--ink-50);
  font-size: 10px;
  font-weight: 700;
  cursor: help;
  border: 1px solid var(--hairline);
}
.assembled-hint:focus { outline: 1px solid var(--amber); outline-offset: 1px; }
.error { color: var(--cond-dmg); margin: 4px 0 12px; font-size: 12px; }
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 18px;
}
.result { padding-top: 4px; }
.stats { display: flex; gap: 12px; margin-bottom: 16px; }
.stat {
  flex: 1;
  text-align: center;
  background: var(--bg-0);
  border-radius: 6px;
  padding: 14px 8px;
}
.stat-val {
  display: block;
  font-size: 24px;
  font-weight: 700;
  color: var(--amber);
  font-family: var(--font-display), serif;
}
.stat-lbl {
  display: block;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--ink-50);
  margin-top: 4px;
}
.warnings {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  max-height: 160px;
  overflow-y: auto;
  font-size: 12px;
  color: var(--ink-50);
}
.warnings li {
  padding: 4px 0;
  border-bottom: 1px solid var(--hairline);
}
.warnings li::before { content: '\26A0\FE0F  '; }
.deck-summary {
  padding: 10px 12px;
  background: var(--bg-0);
  border-radius: 6px;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.format-badge {
  text-transform: uppercase;
  font-size: 10px;
  letter-spacing: 0.05em;
  color: var(--ink-50);
  background: var(--bg-1);
  padding: 2px 8px;
  border-radius: 999px;
}
.conflict {
  padding-top: 4px;
}
.conflict-msg {
  margin: 0 0 10px;
  padding: 12px;
  background: var(--bg-0);
  border-left: 2px solid var(--amber);
  border-radius: 0 4px 4px 0;
  font-size: 13px;
}
.conflict-hint {
  margin: 0 0 16px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--ink-50);
}
.conflict-hint strong { color: var(--amber); }
</style>
