<template>
  <div class="page">
    <header class="hero">
      <h1>My Favorites</h1>
      <p>Your starred images.</p>
    </header>

    <section class="panel">
      <div class="row">
        <label>
          Sort
          <select v-model="sort">
            <option value="date_new">Date New-Old</option>
            <option value="date_old">Date Old-New</option>
            <option value="name_asc">Name A-Z</option>
            <option value="name_desc">Name Z-A</option>
          </select>
        </label>
        <label>
          Limit
          <input v-model.number="limit" type="number" min="1" max="200" />
        </label>
        <label>
          View
          <select v-model="viewMode">
            <option value="list">List</option>
            <option value="grid">Grid</option>
          </select>
        </label>
      </div>
    </section>

    <section class="results">
      <div class="meta">
        <span v-if="loading">Loading…</span>
        <span v-else-if="total === null">Results: —</span>
        <span v-else>Results: {{ results.length }} of {{ total }} ({{ total }} images)</span>
      </div>
      <results-list
        v-if="viewMode === 'list'"
        :items="results"
        :offset="offset"
        :selected-ids="selectedIds"
        :can-favorite="true"
        :file-url="fileUrl"
        :thumb-url="thumbUrl"
        :format-ts="formatTs"
        :copy-link="copyLink"
        :file-name="fileName"
        @open="openViewer"
        @toggle-favorite="toggleFavorite"
        @update:selected-ids="selectedIds = $event"
      />
      <results-grid
        v-else
        :items="results"
        :offset="offset"
        :selected-ids="selectedIds"
        :can-favorite="true"
        :can-trash="isAdmin"
        :file-url="fileUrl"
        :thumb-url="thumbUrl"
        :format-ts="formatTs"
        :copy-link="copyLink"
        :file-name="fileName"
        @open="openViewer"
        @toggle-favorite="toggleFavorite"
        @update:selected-ids="selectedIds = $event"
        @request-trash="requestTrash"
      />
      <image-viewer
        :results="results"
        :start-id="viewerStartId"
        :is-open="viewerOpen"
        :file-url="fileUrl"
        :current-user="currentUser"
        @close="closeViewer"
        @trashed="onItemTrashed"
      />
      <video-viewer
        :results="results.filter((r) => r.type === 'video')"
        :start-id="videoViewerStartId"
        :is-open="videoViewerOpen"
        :video-url="videoUrl"
        :current-user="currentUser"
        @close="closeVideoViewer"
        @trashed="onItemTrashed"
      />
      <div v-if="toast" class="toast">{{ toast }}</div>
    </section>
  </div>
</template>

<script>
import ResultsGrid from "../components/ResultsGrid.vue";
import ResultsList from "../components/ResultsList.vue";
import ImageViewer from "../components/ImageViewer.vue";
import VideoViewer from "../components/VideoViewer.vue";

export default {
  name: "FavoritesPage",
  components: { ResultsGrid, ResultsList, ImageViewer, VideoViewer },
  data() {
    return {
      results: [],
      total: null,
      offset: 0,
      limit: 50,
      sort: "date_new",
      viewMode: "grid",
      page: 1,
      selectedIds: [],
      loading: false,
      toast: "",
      viewerOpen: false,
      viewerStartId: 0,
      videoViewerOpen: false,
      videoViewerStartId: 0,
      currentUser: null
    };
  },
  computed: {
    isAdmin() {
      return !!(this.currentUser && this.currentUser.is_admin);
    }
  },
  mounted() {
    const prefs = window.__wa_prefs || null;
    this.applyPrefs(prefs);
    if (!prefs) {
      this.viewMode = window.matchMedia("(min-width: 1024px)").matches ? "grid" : "list";
    }
    this.currentUser = window.__wa_current_user || null;
    this.fetchFavorites();
    window.addEventListener("wa-prefs-changed", this.onPrefsChanged);
  },
  beforeUnmount() {
    window.removeEventListener("wa-prefs-changed", this.onPrefsChanged);
  },
  watch: {
    sort() {
      this.fetchFavorites();
    },
    limit() {
      this.fetchFavorites();
    },
    viewMode() {}
  },
  methods: {
    onPrefsChanged(event) {
      this.applyPrefs(event.detail || null);
    },
    applyPrefs(prefs) {
      if (!prefs) {
        return;
      }
      this.limit = prefs.page_size || 50;
      this.viewMode = prefs.default_view || "grid";
      const sort = prefs.sort_mode || "name_az";
      if (sort === "name_az") {
        this.sort = "name_asc";
      } else if (sort === "name_za") {
        this.sort = "name_desc";
      } else if (sort === "date_new_old") {
        this.sort = "date_new";
      } else if (sort === "date_old_new") {
        this.sort = "date_old";
      }
    },
    async fetchFavorites() {
      this.loading = true;
      try {
        const qs = new URLSearchParams();
        qs.set("limit", String(this.limit));
        qs.set("offset", String((this.page - 1) * this.limit));
        qs.set("sort", this.sort);
        const res = await fetch(`/api/favorites/list?${qs.toString()}`);
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.results = [];
          this.total = 0;
          return;
        }
        this.results = data.items || [];
        this.total = typeof data.total === "number" ? data.total : this.results.length;
        this.offset = typeof data.offset === "number" ? data.offset : 0;
      } finally {
        this.loading = false;
      }
    },
    fileUrl(id) {
      return `${window.location.origin}/api/file?id=${id}`;
    },
    videoUrl(id) {
      return `${window.location.origin}/api/video?id=${id}`;
    },
    thumbUrl(id) {
      return `${window.location.origin}/api/thumb?id=${id}`;
    },
    formatTs(ts) {
      const d = new Date(ts * 1000);
      return d.toISOString().slice(0, 10);
    },
    fileName(path) {
      const parts = path.split("/");
      return parts[parts.length - 1] || path;
    },
    copyLink(id) {
      const text = this.fileUrl(id);
      navigator.clipboard?.writeText(text).catch(() => {});
    },
    async toggleFavorite(fileId) {
      const row = this.results.find((r) => r.id === fileId);
      if (!row) return;
      const prev = row.is_favorite;
      row.is_favorite = !prev;
      try {
        const res = await fetch("/api/favorites/toggle", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ file_id: fileId })
        });
        if (this.handleAuthError(res)) {
          row.is_favorite = prev;
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          row.is_favorite = prev;
          this.showToast(data.error || "Failed to toggle favorite");
          return;
        }
        row.is_favorite = !!data.is_favorite;
        if (!row.is_favorite) {
          this.results = this.results.filter((r) => r.id !== fileId);
        }
      } catch (err) {
        row.is_favorite = prev;
        this.showToast("Failed to toggle favorite");
      }
    },
    async requestTrash(row) {
      if (!this.isAdmin || !row || !row.id) {
        return;
      }
      const ok = window.confirm(`Move to Trash?
${row.path || row.rel_path || row.id}
This is reversible from Admin -> Trash.`);
      if (!ok) {
        return;
      }
      try {
        const res = await fetch("/api/admin/trash", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: row.id, type: row.type || "image" })
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.showToast(data.error || "Failed to move to trash");
          return;
        }
        this.showToast("Moved to Trash");
        await this.fetchFavorites();
      } catch (_e) {
        this.showToast("Failed to move to trash");
      }
    },
    showToast(message) {
      this.toast = message;
      setTimeout(() => {
        this.toast = "";
      }, 1500);
    },
    openViewer(id) {
      const row = this.results.find((r) => r.id === id);
      if (!row) {
        return;
      }
      if (row.type === "video") {
        this.viewerOpen = false;
        this.videoViewerStartId = id;
        this.videoViewerOpen = true;
        return;
      }
      this.viewerStartId = id;
      this.videoViewerOpen = false;
      this.viewerOpen = true;
    },
    closeViewer() {
      this.viewerOpen = false;
    },
    closeVideoViewer() {
      this.videoViewerOpen = false;
    },
    handleAuthError(res) {
      if (res.status === 401 || res.status === 403) {
        window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
        this.$router.push("/login");
        return true;
      }
      return false;
    }
  }
};
</script>
