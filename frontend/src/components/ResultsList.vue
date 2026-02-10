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
      <tr v-for="(row, idx) in items" :key="row.id">
        <td class="num">{{ offset + idx + 1 }}</td>
        <td>
          <input
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
              v-if="row.type === 'image'"
              :src="thumbUrl(row.id)"
              :alt="fileName(row.path)"
              loading="lazy"
              class="thumb-img"
              @load="markLoaded"
            />
            <span v-else class="thumb-placeholder">▶</span>
          </button>
        </td>
        <td class="fav">
          <button
            v-if="canFavorite"
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
          <button class="copy" type="button" @click="copyLink(row.id)">Copy</button>
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
    toggleSelected(id, checked) {
      const already = this.selectedIds.includes(id);
      const next = checked
        ? (already ? this.selectedIds : [...this.selectedIds, id])
        : this.selectedIds.filter((x) => x !== id);
      this.$emit("update:selectedIds", next);
    },
    markLoaded(event) {
      event.target.classList.add("loaded");
    }
  }
};
</script>
