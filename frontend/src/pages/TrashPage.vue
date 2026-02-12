<template>
  <div class="page">
    <header class="hero">
      <h1>Trash</h1>
      <p>Review trashed media. Restore or permanently delete.</p>
    </header>

    <section class="panel">
      <div class="toolbar">
        <div class="summary">Total: {{ total }} entries • Page {{ page }} of {{ totalPages }}</div>
        <label>
          Page size
          <select v-model.number="pageSize" @change="applyFilters">
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </label>
      </div>

      <div class="filters">
        <label>
          Path contains
          <input v-model.trim="filters.q" type="text" placeholder="2020/IMG_" />
        </label>
        <label>
          Sort
          <select v-model="filters.sort">
            <option value="deleted_at_desc">Trashed time (newest first)</option>
            <option value="deleted_at_asc">Trashed time (oldest first)</option>
          </select>
        </label>
        <div class="filter-actions">
          <button class="inline" :disabled="loading" @click="applyFilters">Apply</button>
          <button class="inline" :disabled="loading" @click="clearFilters">Clear</button>
        </div>
      </div>

      <div class="bulk-actions" v-if="items.length">
        <span class="selected">Selected: {{ selectedIds.length }}</span>
        <button class="inline" :disabled="loading || selectedIds.length === 0" @click="openBulkConfirm('restore')">
          Restore selected
        </button>
        <button class="inline danger" :disabled="loading || selectedIds.length === 0" @click="openBulkConfirm('purge')">
          Purge selected
        </button>
        <button class="inline danger" :disabled="loading || total === 0" @click="openBulkConfirm('empty')">
          Empty trash
        </button>
        <button class="inline" v-if="selectedIds.length" @click="clearSelection">Unselect all</button>
      </div>

      <div class="pager" v-if="total > 0">
        <button :disabled="page === 1 || loading" @click="prevPage">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <button :disabled="page >= totalPages || loading" @click="nextPage">Next</button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="!loading && items.length === 0" class="muted">Trash is empty.</p>

      <table v-if="items.length" class="tags-table trash-table">
        <thead>
          <tr>
            <th>
              <input type="checkbox" :checked="allSelectedOnPage" @change="toggleSelectAll($event.target.checked)" />
            </th>
            <th>Thumb</th>
            <th>Filename</th>
            <th>Type</th>
            <th>Trashed at</th>
            <th>Trashed by</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.trash_id">
            <td>
              <input
                type="checkbox"
                :value="row.trash_id"
                :checked="selectedIds.includes(row.trash_id)"
                @change="toggleSelected(row.trash_id, $event.target.checked)"
              />
            </td>
            <td class="thumb">
              <img class="thumb-img" :src="row.thumb_url" alt="thumb" loading="lazy" @error="onThumbError" />
              <span class="thumb-fallback">{{ row.type === "video" ? "🎬" : "🖼" }}</span>
            </td>
            <td class="path" :title="row.rel_path">{{ fileName(row.rel_path) }}</td>
            <td>{{ row.type }}</td>
            <td>{{ row.deleted_at }}</td>
            <td>{{ row.deleted_by || "—" }}</td>
          </tr>
        </tbody>
      </table>

      <div class="pager" v-if="total > 0">
        <button :disabled="page === 1 || loading" @click="prevPage">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <button :disabled="page >= totalPages || loading" @click="nextPage">Next</button>
      </div>
    </section>

    <div v-if="confirmOpen" class="modal-backdrop" @click.self="closeConfirm">
      <div class="modal confirm-modal">
        <h3>{{ confirmTitle }}</h3>
        <p>{{ confirmMessage }}</p>
        <label v-if="confirmNeedsType">
          Type <strong>PURGE</strong> to continue
          <input v-model.trim="confirmInput" type="text" />
        </label>
        <div class="modal-actions">
          <button class="inline" :disabled="confirmNeedsType && confirmInput !== 'PURGE'" @click="executeConfirm">Confirm</button>
          <button class="inline" @click="closeConfirm">Cancel</button>
        </div>
      </div>
    </div>

    <div v-if="resultOpen" class="modal-backdrop" @click.self="closeResult">
      <div class="modal result-modal">
        <h3>{{ resultTitle }}</h3>
        <p>{{ resultSummary }}</p>
        <div v-if="resultErrors.length">
          <strong>Errors</strong>
          <ul class="errors-list">
            <li v-for="(e, idx) in resultErrors" :key="idx">#{{ e.id }}: {{ e.error }}</li>
          </ul>
        </div>
        <div class="modal-actions">
          <button class="inline" @click="closeResult">Close</button>
        </div>
      </div>
    </div>

    <div v-if="toast" class="toast">{{ toast }}</div>
  </div>
</template>

<script>
export default {
  name: "TrashPage",
  data() {
    return {
      loading: false,
      error: "",
      items: [],
      page: 1,
      pageSize: 50,
      total: 0,
      selectedIds: [],
      toast: "",
      filters: {
        q: "",
        sort: "deleted_at_desc"
      },
      confirmOpen: false,
      confirmAction: "",
      confirmInput: "",
      resultOpen: false,
      resultTitle: "",
      resultSummary: "",
      resultErrors: []
    };
  },
  computed: {
    totalPages() {
      if (!this.total) {
        return 1;
      }
      return Math.max(1, Math.ceil(this.total / this.pageSize));
    },
    allSelectedOnPage() {
      if (this.items.length === 0) {
        return false;
      }
      return this.items.every((row) => this.selectedIds.includes(row.trash_id));
    },
    confirmNeedsType() {
      return this.confirmAction === "purge" && this.selectedIds.length >= 20;
    },
    confirmTitle() {
      if (this.confirmAction === "restore") {
        return "Restore selected";
      }
      if (this.confirmAction === "purge") {
        return "Purge selected";
      }
      return "Empty trash";
    },
    confirmMessage() {
      if (this.confirmAction === "restore") {
        return `Restore ${this.selectedIds.length} items back to the library?`;
      }
      if (this.confirmAction === "purge") {
        return `Permanently delete ${this.selectedIds.length} items from Trash? This cannot be undone.`;
      }
      return "Permanently delete all current trash items? This cannot be undone.";
    }
  },
  mounted() {
    this.fetchTrash();
  },
  methods: {
    fileName(relPath) {
      if (!relPath) {
        return "";
      }
      const parts = String(relPath).split("/");
      return parts[parts.length - 1] || relPath;
    },
    async fetchTrash() {
      this.loading = true;
      this.error = "";
      try {
        const qs = new URLSearchParams();
        qs.set("status", "trashed");
        qs.set("page", String(this.page));
        qs.set("page_size", String(this.pageSize));
        qs.set("sort", this.filters.sort);
        if (this.filters.q) {
          qs.set("q", this.filters.q);
        }
        const res = await fetch(`/api/admin/trash?${qs.toString()}`);
        if (res.status === 401 || res.status === 403) {
          window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load trash";
          return;
        }
        this.items = Array.isArray(data.items) ? data.items : [];
        this.total = Number(data.total || 0);
        this.page = Number(data.page || 1);
        this.pageSize = Number(data.page_size || this.pageSize);
        this.selectedIds = this.selectedIds.filter((id) => this.items.some((r) => r.trash_id === id));
      } catch (_e) {
        this.error = "Failed to load trash";
      } finally {
        this.loading = false;
      }
    },
    applyFilters() {
      this.page = 1;
      this.clearSelection();
      this.fetchTrash();
    },
    clearFilters() {
      this.filters = { q: "", sort: "deleted_at_desc" };
      this.applyFilters();
    },
    prevPage() {
      if (this.page > 1) {
        this.page -= 1;
        this.clearSelection();
        this.fetchTrash();
      }
    },
    nextPage() {
      if (this.page < this.totalPages) {
        this.page += 1;
        this.clearSelection();
        this.fetchTrash();
      }
    },
    onThumbError(event) {
      const img = event && event.target;
      if (!img) {
        return;
      }
      img.style.display = "none";
      const sibling = img.parentElement && img.parentElement.querySelector(".thumb-fallback");
      if (sibling) {
        sibling.style.display = "inline";
      }
    },
    toggleSelected(id, checked) {
      if (checked) {
        if (!this.selectedIds.includes(id)) {
          this.selectedIds.push(id);
        }
      } else {
        this.selectedIds = this.selectedIds.filter((x) => x !== id);
      }
    },
    toggleSelectAll(checked) {
      if (!checked) {
        this.clearSelection();
        return;
      }
      this.selectedIds = this.items.map((row) => row.trash_id);
    },
    clearSelection() {
      this.selectedIds = [];
    },
    openBulkConfirm(action) {
      this.confirmAction = action;
      this.confirmInput = "";
      this.confirmOpen = true;
    },
    closeConfirm() {
      this.confirmOpen = false;
      this.confirmAction = "";
      this.confirmInput = "";
    },
    async executeConfirm() {
      const action = this.confirmAction;
      this.closeConfirm();
      if (action === "restore") {
        await this.runBulk("/api/admin/trash/restore-bulk", this.selectedIds, "Restored");
      } else if (action === "purge") {
        await this.runBulk("/api/admin/trash/purge-bulk", this.selectedIds, "Purged");
      } else if (action === "empty") {
        await this.runBulk("/api/admin/trash/empty", null, "Trash emptied");
      }
    },
    async runBulk(url, ids, okLabel) {
      this.loading = true;
      this.error = "";
      try {
        const payload = ids ? { ids } : {};
        const res = await fetch(url, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        if (res.status === 401 || res.status === 403) {
          window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok && res.status !== 207) {
          this.error = data.error || "Operation failed";
          return;
        }

        const result = data.result || {};
        const changed = Number(result.restored_count || result.purged_count || 0);
        this.showToast(`${okLabel} ${changed} item(s)`);

        const errors = Array.isArray(result.errors) ? result.errors : [];
        if (errors.length) {
          this.resultTitle = `${okLabel} with issues`;
          this.resultSummary = `${changed} item(s) processed, ${errors.length} issue(s).`;
          this.resultErrors = errors;
          this.resultOpen = true;
        }

        this.clearSelection();
        await this.fetchTrash();
      } catch (_e) {
        this.error = "Operation failed";
      } finally {
        this.loading = false;
      }
    },
    closeResult() {
      this.resultOpen = false;
      this.resultTitle = "";
      this.resultSummary = "";
      this.resultErrors = [];
    },
    showToast(message) {
      this.toast = message;
      setTimeout(() => {
        this.toast = "";
      }, 1800);
    }
  }
};
</script>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.summary {
  color: var(--muted);
}

.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
  margin-bottom: 10px;
}

.filters label {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-actions {
  display: flex;
  gap: 8px;
  align-items: flex-end;
}

.bulk-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.selected {
  color: var(--muted);
  margin-right: 8px;
}

.trash-table .thumb {
  width: 92px;
}

.thumb-img {
  width: 72px;
  height: 72px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #d9cfbd;
  background: #f2ede3;
}

.thumb-fallback {
  display: none;
  font-size: 22px;
}

.trash-table .path {
  word-break: break-all;
  max-width: 600px;
}

.inline.danger {
  border-color: #a84a3a;
  color: #a84a3a;
}

.errors-list {
  max-height: 220px;
  overflow: auto;
}
</style>
