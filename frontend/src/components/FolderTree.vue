<template>
  <div class="folder-tree panel">
    <div class="folder-head">
      <h3>Folders</h3>
      <button v-if="selectedRelPath" class="clear" type="button" @click="clearSelection">Clear</button>
    </div>

    <p v-if="selectedRelPath" class="selected" :title="selectedRelPath">{{ selectedRelPath }}</p>
    <p v-if="loading" class="muted">Loading folders…</p>
    <p v-else-if="error" class="error">{{ error }}</p>
    <p v-else-if="rows.length === 0" class="muted">No folders indexed</p>

    <div v-else class="tree-list">
      <div
        v-for="row in rows"
        :key="row.id"
        class="tree-row"
        :class="{ active: selectedRelPath === row.rel_path }"
        :style="{ paddingLeft: `${Math.max(0, (row.depth - 1) * 14 + 8)}px` }"
      >
        <button
          class="expander"
          type="button"
          :disabled="!row.has_children"
          @click="toggle(row)"
          :aria-label="expanded[row.id] ? 'Collapse folder' : 'Expand folder'"
        >
          <span v-if="row.has_children">{{ expanded[row.id] ? '▾' : '▸' }}</span>
        </button>
        <button class="name" type="button" :title="row.rel_path" @click="selectRow(row)">
          {{ row.name }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "FolderTree",
  props: {
    selectedRelPath: { type: String, default: "" }
  },
  data() {
    return {
      roots: [],
      childrenByParent: {},
      expanded: {},
      loading: false,
      error: ""
    };
  },
  computed: {
    rows() {
      const out = [];
      const visit = (nodes) => {
        nodes.forEach((node) => {
          out.push(node);
          if (this.expanded[node.id]) {
            visit(this.childrenByParent[node.id] || []);
          }
        });
      };
      visit(this.roots);
      return out;
    }
  },
  mounted() {
    this.loadRoots();
  },
  methods: {
    async loadRoots() {
      this.loading = true;
      this.error = "";
      try {
        const res = await fetch("/api/tree/roots");
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load folders";
          return;
        }
        this.roots = Array.isArray(data) ? data : [];
      } catch (err) {
        this.error = "Failed to load folders";
      } finally {
        this.loading = false;
      }
    },
    async loadChildren(parentId) {
      if (this.childrenByParent[parentId]) {
        return;
      }
      const qs = new URLSearchParams({ parent_id: String(parentId) });
      const res = await fetch(`/api/tree?${qs.toString()}`);
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.error || "Failed to load child folders");
      }
      this.childrenByParent = {
        ...this.childrenByParent,
        [parentId]: Array.isArray(data) ? data : []
      };
    },
    async toggle(row) {
      if (!row.has_children) {
        return;
      }
      if (this.expanded[row.id]) {
        this.expanded = { ...this.expanded, [row.id]: false };
        return;
      }
      try {
        await this.loadChildren(row.id);
        this.expanded = { ...this.expanded, [row.id]: true };
      } catch (err) {
        this.error = err.message || "Failed to load child folders";
      }
    },
    selectRow(row) {
      this.$emit("select", {
        id: row.id,
        rel_path: row.rel_path,
        name: row.name
      });
    },
    clearSelection() {
      this.$emit("clear");
    }
  }
};
</script>

<style scoped>
.folder-tree {
  padding: 10px;
}
.folder-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.folder-head h3 {
  margin: 0;
  font-size: 16px;
}
.selected {
  margin: 0 0 8px;
  font-size: 12px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tree-list {
  display: grid;
  gap: 2px;
  max-height: calc(100vh - 250px);
  overflow: auto;
}
.tree-row {
  display: flex;
  align-items: center;
  min-height: 28px;
  border-radius: 6px;
}
.tree-row.active {
  background: #f1eadf;
}
.expander {
  width: 22px;
  height: 24px;
  background: transparent;
  border: none;
  color: var(--ink);
  padding: 0;
}
.expander:disabled {
  opacity: 0.35;
}
.name {
  border: none;
  background: transparent;
  color: var(--ink);
  text-align: left;
  width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding: 4px 6px;
}
.name:hover {
  text-decoration: underline;
}
</style>
