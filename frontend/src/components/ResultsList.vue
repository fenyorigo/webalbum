<template>
  <table class="results-table" v-if="items.length">
    <thead>
      <tr>
        <th>#</th>
        <th></th>
        <th></th>
        <th></th>
        <th>Path</th>
        <th>Type</th>
        <th>Taken</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="(row, idx) in items" :key="`${row.entity || 'media'}:${row.id}`">
        <td class="num">{{ offset + idx + 1 }}</td>
        <td>
          <input
            v-if="isSelectable(row)"
            type="checkbox"
            :value="row.id"
            :checked="selectedIds.includes(row.id)"
            @click.stop
            @change="toggleSelected(row.id, $event.target.checked)"
          />
        </td>
        <td class="thumb">
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
        </td>
        <td class="fav">
          <button
            v-if="canFavorite && row.entity !== 'asset'"
            class="star"
            type="button"
            :aria-label="row.is_favorite ? 'Unstar' : 'Star'"
            @click.stop="$emit('toggle-favorite', row.id)"
          >
            {{ row.is_favorite ? "★" : "☆" }}
          </button>
        </td>
        <td class="path">
          <button class="link text" type="button" @click="$emit('open', row.id)">
            {{ row.path }}
          </button>
          <div class="item-id">ID: {{ dbId(row) }}</div>
          <button class="copy" type="button" @click="copyLink(row)">Copy</button>
        </td>
        <td>{{ row.type }}</td>
        <td><span v-if="row.taken_ts" class="ts">{{ formatTs(row.taken_ts) }}</span></td>
      </tr>
    </tbody>
  </table>
</template>

<script>
export default {
  name: "ResultsList",
  props: {
    items: { type: Array, required: true },
    offset: { type: Number, required: true },
    selectedIds: { type: Array, required: true },
    canFavorite: { type: Boolean, default: true },
    fileUrl: { type: Function, required: true },
    thumbUrl: { type: Function, required: true },
    formatTs: { type: Function, required: true },
    copyLink: { type: Function, required: true },
    fileName: { type: Function, required: true }
  },
  methods: {
    isSelectable(row) {
      return !!row;
    },
    toggleSelected(id, checked) {
      const already = this.selectedIds.includes(id);
      const next = checked
        ? (already ? this.selectedIds : [...this.selectedIds, id])
        : this.selectedIds.filter((x) => x !== id);
      this.$emit("update:selectedIds", next);
    },
    markLoaded(event) {
      event.target.classList.add("loaded");
    },
    dbId(row) {
      if (row && row.entity === "asset" && row.asset_id) {
        return row.asset_id;
      }
      return row && row.id !== undefined ? row.id : "-";
    }
  }
};
</script>

<style scoped>
.item-id {
  color: #6f6556;
  font-size: 12px;
  margin-top: 2px;
}
</style>
