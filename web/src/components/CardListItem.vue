<script setup>
defineProps({
  entry: { type: Object, required: true },
  showLocation: { type: Boolean, default: true },
})
</script>

<template>
  <li class="card-row">
    <div class="card-art-thumb">
      <img
        v-if="entry.card?.image_uri"
        :src="entry.card.image_uri"
        :alt="entry.card?.name"
        loading="lazy"
        class="card-thumb-img"
      />
      <span v-else class="card-thumb-placeholder" aria-hidden="true">?</span>
    </div>
    <div class="card-info">
      <span class="card-name">{{ entry.card?.name ?? '—' }}</span>
      <span class="card-meta">
        {{ entry.card?.set?.toUpperCase() }} ·
        {{ entry.condition }}
        <template v-if="entry.foil"> · Foil</template>
      </span>
    </div>
    <span class="card-qty">×{{ entry.quantity }}</span>
    <span v-if="showLocation && entry.location_name" class="card-location">
      {{ entry.location_name }}
    </span>
  </li>
</template>

<style scoped>
.card-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 16px;
  border-bottom: 1px solid var(--hairline);
  transition: background 0.1s ease;
}
.card-row:last-child { border-bottom: none; }
.card-row:hover { background: var(--bg-2); }

.card-art-thumb {
  width: 36px;
  height: 36px;
  border-radius: 4px;
  overflow: hidden;
  flex-shrink: 0;
  background: var(--bg-2);
  display: flex;
  align-items: center;
  justify-content: center;
}
.card-thumb-img { width: 100%; height: 100%; object-fit: cover; }
.card-thumb-placeholder { font-size: 16px; color: var(--ink-30); }

.card-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
  min-width: 0;
}
.card-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-100);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.card-meta {
  font-size: 11px;
  color: var(--ink-50);
}
.card-qty {
  font-size: 12px;
  font-weight: 600;
  color: var(--ink-70);
  flex-shrink: 0;
}
.card-location {
  font-size: 11px;
  color: var(--ink-30);
  flex-shrink: 0;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
