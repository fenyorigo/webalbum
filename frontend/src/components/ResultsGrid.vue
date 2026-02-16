<template>
  <div class="results-grid" v-if="items.length">
    <div
      v-for="(row, idx) in items"
      :key="`${row.entity || 'media'}:${row.id}`"
      class="grid-item"
      tabindex="0"
      @keydown.space.prevent="toggleSelected(row.id, row)"
    >
      <div class="grid-thumb">
        <button class="link" type="button" @click="$emit('open', row.id)">
          <img
            v-if="row.type === 'image' || row.type === 'video' || row.type === 'doc'"
            :src="thumbUrl(row)"
            :alt="fileName(row.path)"
            loading="lazy"
            class="thumb-img"
            @load="markLoaded"
          />
          <span v-else class="thumb-placeholder">🎵</span>
        </button>
        <button
          v-if="canFavorite && row.entity !== 'asset'"
          class="grid-star"
          type="button"
          :aria-label="row.is_favorite ? 'Unstar' : 'Star'"
          @click.stop="$emit('toggle-favorite', row.id)"
        >
          {{ row.is_favorite ? "★" : "☆" }}
        </button>
        <label class="grid-check right">
          <input
            type="checkbox"
            :value="row.id"
            :checked="selectedIds.includes(row.id)"
            @click.stop
            @change="toggleSelected(row.id, row)"
          />
        </label>
        <span class="grid-num">{{ offset + idx + 1 }}</span>
      </div>
      <div class="grid-meta">
        <button class="grid-name" type="button" :title="row.path" @click="$emit('open', row.id)">
          {{ fileName(row.path) }}
        </button>
        <span class="grid-date" v-if="row.taken_ts">{{ formatTs(row.taken_ts) }}</span>
      </div>
      <div class="grid-actions">
        <button
          v-if="canTrash && row.entity !== 'asset'"
          class="trash"
          type="button"
          aria-label="Move to Trash"
          @click.stop="$emit('request-trash', row)"
        >
          🗑
        </button>
        <span v-else></span>
        <button class="copy" type="button" @click="copyLink(row)">Copy</button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "ResultsGrid",
  props: {
    items: { type: Array, required: true },
    offset: { type: Number, required: true },
    selectedIds: { type: Array, required: true },
    canFavorite: { type: Boolean, default: true },
    canTrash: { type: Boolean, default: false },
    fileUrl: { type: Function, required: true },
    thumbUrl: { type: Function, required: true },
    formatTs: { type: Function, required: true },
    copyLink: { type: Function, required: true },
    fileName: { type: Function, required: true }
  },
  methods: {
    toggleSelected(id) {
      const next = this.selectedIds.includes(id)
        ? this.selectedIds.filter((x) => x !== id)
        : [...this.selectedIds, id];
      this.$emit("update:selectedIds", next);
    },
    markLoaded(event) {
      event.target.classList.add("loaded");
    }
  }
};
</script>

<style scoped>
.grid-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.trash {
  border: 1px solid #d6c9b5;
  background: #fff;
  border-radius: 8px;
  padding: 4px 8px;
  cursor: pointer;
}
</style>
