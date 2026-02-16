<template>
  <div class="page">
    <header class="hero">
      <h1>Assets</h1>
      <p>Documents and audio indexed in the archive.</p>
    </header>

    <section class="panel">
      <div class="row">
        <button type="button" @click="scanNow" :disabled="loading">Scan documents and audio</button>
        <button type="button" class="inline" @click="refreshJobs" :disabled="loading">Refresh job status</button>
      </div>
      <p v-if="jobs" class="muted">
        Jobs: queued {{ jobs.counts.queued || 0 }}, running {{ jobs.counts.running || 0 }}, done {{ jobs.counts.done || 0 }}, error {{ jobs.counts.error || 0 }}
      </p>
      <p v-if="jobs && jobs.split" class="muted">
        Split: queued thumb {{ jobs.split.queued.doc_thumb || 0 }}, queued preview {{ jobs.split.queued.doc_pdf_preview || 0 }}
        <span v-if="jobs.split.queued.other">, queued other {{ jobs.split.queued.other }}</span>
        · running thumb {{ jobs.split.running.doc_thumb || 0 }}, running preview {{ jobs.split.running.doc_pdf_preview || 0 }}
        <span v-if="jobs.split.running.other">, running other {{ jobs.split.running.other }}</span>
      </p>
      <div class="row">
        <label>
          Search path
          <input v-model.trim="filters.q" type="text" placeholder="2020/Budapest" />
        </label>
        <label>
          Type
          <select v-model="filters.type">
            <option value="">Any</option>
            <option value="doc">Documents</option>
            <option value="audio">Audio</option>
          </select>
        </label>
        <label>
          Ext
          <input v-model.trim="filters.ext" type="text" placeholder="pdf" />
        </label>
        <label>
          Derivative status
          <select v-model="filters.status">
            <option value="">Any</option>
            <option value="pending">Pending</option>
            <option value="ready">Ready</option>
            <option value="error">Error</option>
          </select>
        </label>
        <label>
          Page size
          <select v-model.number="pageSize">
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
        </label>
      </div>
      <div class="row actions">
        <button type="button" @click="applyFilters" :disabled="loading">Apply</button>
        <button type="button" class="inline" @click="clearFilters" :disabled="loading">Clear</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <section class="results">
      <div class="meta">
        <span v-if="loading">Loading…</span>
        <span v-else>Total: {{ total }} · Page {{ page }} of {{ totalPages }}</span>
      </div>
      <div class="status-counters">
        <span>Pending: {{ counters.pending }}</span>
        <span>Running: {{ counters.running }}</span>
        <span>Ready: {{ counters.ready }}</span>
        <span>No processing needed: {{ counters.no_processing }}</span>
        <span>Failed: {{ counters.failed }}</span>
      </div>
      <div class="status-tabs">
        <button type="button" class="inline" :class="{ active: statusTab === 'pending' }" @click="statusTab = 'pending'">Pending</button>
        <button type="button" class="inline" :class="{ active: statusTab === 'running' }" @click="statusTab = 'running'">Running</button>
        <button type="button" class="inline" :class="{ active: statusTab === 'ready' }" @click="statusTab = 'ready'">Ready</button>
        <button type="button" class="inline" :class="{ active: statusTab === 'no_processing' }" @click="statusTab = 'no_processing'">No processing needed</button>
        <button type="button" class="inline" :class="{ active: statusTab === 'failed' }" @click="statusTab = 'failed'">Failed</button>
        <button
          type="button"
          class="inline"
          @click="clearList"
          :disabled="clearableCount === 0"
          title="Only completed items can be cleared."
        >
          Clear list
        </button>
      </div>

      <div class="pager" v-if="totalPages > 1">
        <button :disabled="loading || page <= 1" @click="prevPage">Previous</button>
        <button :disabled="loading || page >= totalPages" @click="nextPage">Next</button>
      </div>

      <table class="results-table" v-if="rowsForTab.length">
        <thead>
          <tr>
            <th>ID</th>
            <th>Path</th>
            <th>Type</th>
            <th>Ext</th>
            <th>Size</th>
            <th>MTime</th>
            <th>
              <button class="sort-btn" type="button" @click="toggleSort('thumb_status')">
                Thumb {{ sortLabel('thumb_status') }}
              </button>
            </th>
            <th>
              <button class="sort-btn" type="button" @click="toggleSort('preview_status')">
                Preview {{ sortLabel('preview_status') }}
              </button>
            </th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rowsForTab" :key="row.id">
            <td>{{ row.id }}</td>
            <td :title="row.rel_path">{{ row.rel_path }}</td>
            <td>{{ row.type }}</td>
            <td>{{ row.ext }}</td>
            <td>{{ formatSize(row.size) }}</td>
            <td>{{ formatTs(row.mtime) }}</td>
            <td>{{ displayStatus(row, "thumb") }}</td>
            <td>{{ displayStatus(row, "preview") }}</td>
            <td>{{ displayOverallStatus(row) }}</td>
            <td class="actions-col">
              <button class="inline" type="button" @click="requeue(row, 'thumb')" :disabled="loading">Requeue thumb</button>
              <button
                v-if="canRequeuePreview(row)"
                class="inline"
                type="button"
                @click="requeue(row, 'pdf_preview')"
                :disabled="loading"
              >
                Requeue preview
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else-if="!loading" class="muted">No items in this section.</p>

      <div class="pager" v-if="totalPages > 1">
        <button :disabled="loading || page <= 1" @click="prevPage">Previous</button>
        <button :disabled="loading || page >= totalPages" @click="nextPage">Next</button>
      </div>
    </section>

    <div v-if="toast" class="toast">{{ toast }}</div>
  </div>
</template>

<script>
export default {
  name: "AssetsPage",
  data() {
    return {
      loading: false,
      error: "",
      items: [],
      total: 0,
      totalPages: 1,
      page: 1,
      pageSize: 50,
      filters: {
        q: "",
        type: "",
        ext: "",
        status: ""
      },
      jobs: {
        counts: { queued: 0, running: 0, done: 0, error: 0 },
        recent_errors: [],
        running: []
      },
      toast: "",
      sortField: "updated_at",
      sortDir: "desc",
      autoRefreshMs: 15000,
      refreshTimer: null,
      statusTab: "pending",
      clearedIds: []
    };
  },
  mounted() {
    this.load();
  },
  beforeUnmount() {
    this.stopAutoRefresh();
  },
  computed: {
    visibleItems() {
      if (!this.items.length) {
        return [];
      }
      const cleared = new Set(this.clearedIds);
      return this.items.filter((row) => !cleared.has(Number(row.id)));
    },
    counters() {
      const out = { pending: 0, running: 0, ready: 0, no_processing: 0, failed: 0 };
      this.visibleItems.forEach((row) => {
        const st = this.overallStatus(row);
        out[st] += 1;
      });
      return out;
    },
    rowsForTab() {
      return this.visibleItems.filter((row) => this.overallStatus(row) === this.statusTab);
    },
    clearableCount() {
      return this.visibleItems.filter((row) => {
        const st = this.overallStatus(row);
        return st === "ready" || st === "no_processing";
      }).length;
    }
  },
  methods: {
    async load() {
      this.loading = true;
      this.error = "";
      try {
        await Promise.all([this.fetchAssets(), this.refreshJobs()]);
      } finally {
        this.loading = false;
      }
    },
    async fetchAssets() {
      const qs = new URLSearchParams();
      qs.set("page", String(this.page));
      qs.set("page_size", String(this.pageSize));
      if (this.filters.q) qs.set("q", this.filters.q);
      if (this.filters.type) qs.set("type", this.filters.type);
      if (this.filters.ext) qs.set("ext", this.filters.ext.toLowerCase());
      if (this.filters.status) qs.set("status", this.filters.status);
      qs.set("sort_field", this.sortField);
      qs.set("sort_dir", this.sortDir);

      const res = await fetch(`/api/admin/assets?${qs.toString()}`);
      if (res.status === 401 || res.status === 403) {
        window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
        this.$router.push("/login");
        return;
      }
      const data = await res.json();
      if (!res.ok) {
        this.error = data.error || "Failed to load assets";
        this.items = [];
        this.total = 0;
        this.totalPages = 1;
        return;
      }
      this.items = data.items || [];
      this.total = Number(data.total || 0);
      this.totalPages = Math.max(1, Number(data.total_pages || 1));
      this.page = Number(data.page || this.page);
      this.pageSize = Number(data.page_size || this.pageSize);
      this.sortField = String(data.sort_field || this.sortField);
      this.sortDir = String(data.sort_dir || this.sortDir);
      this.updateRefreshPolicy();
    },
    async refreshJobs() {
      const res = await fetch("/api/admin/jobs/status");
      if (res.status === 401 || res.status === 403) {
        return;
      }
      const data = await res.json();
      if (res.ok) {
        this.jobs = data;
      }
    },
    startAutoRefresh() {
      this.stopAutoRefresh();
      this.refreshTimer = window.setInterval(async () => {
        if (this.counters.pending === 0 && this.counters.running === 0) {
          this.stopAutoRefresh();
          return;
        }
        if (this.loading) return;
        try {
          await Promise.all([this.fetchAssets(), this.refreshJobs()]);
        } catch (_e) {
          // keep silent; explicit actions still show errors
        }
      }, this.autoRefreshMs);
    },
    stopAutoRefresh() {
      if (this.refreshTimer) {
        clearInterval(this.refreshTimer);
        this.refreshTimer = null;
      }
    },
    updateRefreshPolicy() {
      if ((this.counters.pending > 0 || this.counters.running > 0) && !this.refreshTimer) {
        this.startAutoRefresh();
      }
      if (this.counters.pending === 0 && this.counters.running === 0 && this.refreshTimer) {
        this.stopAutoRefresh();
      }
    },
    toggleSort(field) {
      if (this.sortField === field) {
        this.sortDir = this.sortDir === "asc" ? "desc" : "asc";
      } else {
        this.sortField = field;
        this.sortDir = "asc";
      }
      this.page = 1;
      this.fetchAssets();
    },
    sortLabel(field) {
      if (this.sortField !== field) return "";
      return this.sortDir === "asc" ? "↑" : "↓";
    },
    displayStatus(row, kind) {
      const value = kind === "thumb" ? row.thumb_status : row.preview_status;
      if (!value || value === "na") {
        return "N/A";
      }
      if (value === "error") {
        return "Failed";
      }
      if (value === "pending") {
        return "Pending";
      }
      if (value === "running") {
        return "Running";
      }
      if (value === "ready") {
        return "Ready";
      }
      return String(value);
    },
    overallStatus(row) {
      const thumbApplicable = Number(row.thumb_applicable || 0) === 1;
      const previewApplicable = Number(row.preview_applicable || 0) === 1;
      const thumb = String(row.thumb_status || "").toLowerCase();
      const preview = String(row.preview_status || "").toLowerCase();
      if (!thumbApplicable && !previewApplicable) {
        return "no_processing";
      }
      if (thumb === "error" || preview === "error") {
        return "failed";
      }
      if (thumb === "running" || preview === "running") {
        return "running";
      }
      if (thumb === "pending" || preview === "pending") {
        return "pending";
      }
      return "ready";
    },
    displayOverallStatus(row) {
      const status = this.overallStatus(row);
      if (status === "pending") return "Pending";
      if (status === "running") return "Running";
      if (status === "ready") return "Ready";
      if (status === "no_processing") return "No processing needed";
      if (status === "failed") return "Failed";
      return "N/A";
    },
    clearList() {
      if (this.clearableCount === 0) {
        return;
      }
      const ids = this.visibleItems
        .filter((row) => {
          const st = this.overallStatus(row);
          return st === "ready" || st === "no_processing";
        })
        .map((row) => Number(row.id));
      this.clearedIds = Array.from(new Set([...this.clearedIds, ...ids]));
      this.updateRefreshPolicy();
    },
    async scanNow() {
      if (!window.confirm("Scan your photo library for documents and audio files, then queue any required processing (thumbnails/previews) for supported document types.\n\nNote: Audio items don’t generate thumbnails or previews.")) {
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/admin/assets/scan", { method: "POST" });
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Scan failed";
          return;
        }
        this.toast = `Scan done. Scanned ${data.scanned} (Documents: ${data.scanned_docs || 0}, Audio: ${data.scanned_audio || 0}), enqueued ${data.jobs_enqueued} doc jobs.`;
        setTimeout(() => (this.toast = ""), 2000);
        this.clearedIds = [];
        this.statusTab = "pending";
        await this.load();
      } finally {
        this.loading = false;
      }
    },
    async requeue(row, kind) {
      this.loading = true;
      try {
        const res = await fetch("/api/admin/assets/requeue", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ asset_id: row.id, kind })
        });
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Requeue failed";
          return;
        }
        this.toast = `Queued: ${data.queued.join(", ")}`;
        setTimeout(() => (this.toast = ""), 1800);
        await this.fetchAssets();
        await this.refreshJobs();
      } finally {
        this.loading = false;
      }
    },
    canRequeuePreview(row) {
      return row.type === "doc" && ["txt", "doc", "docx", "xls", "xlsx", "ppt", "pptx"].includes((row.ext || "").toLowerCase());
    },
    applyFilters() {
      this.page = 1;
      this.fetchAssets();
    },
    clearFilters() {
      this.filters = { q: "", type: "", ext: "", status: "" };
      this.page = 1;
      this.clearedIds = [];
      this.fetchAssets();
    },
    prevPage() {
      if (this.page > 1) {
        this.page -= 1;
        this.fetchAssets();
      }
    },
    nextPage() {
      if (this.page < this.totalPages) {
        this.page += 1;
        this.fetchAssets();
      }
    },
    formatSize(size) {
      const n = Number(size || 0);
      if (n < 1024) return `${n} B`;
      if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
      return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    },
    formatTs(ts) {
      const n = Number(ts || 0);
      if (!n) return "—";
      try {
        return new Date(n * 1000).toISOString().slice(0, 19).replace("T", " ");
      } catch (_e) {
        return "—";
      }
    }
  }
};
</script>

<style scoped>
.actions-col {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.sort-btn {
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  color: inherit;
  cursor: pointer;
}

.sort-btn:hover {
  text-decoration: underline;
}

.status-counters {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin: 8px 0;
}

.status-tabs {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.status-tabs .active {
  background: var(--accent);
  color: #fff;
}

</style>
