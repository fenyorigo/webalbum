<template>
  <div class="page">
    <div class="search-layout">
      <aside class="folders-sidebar">
        <folder-tree
          :selected-rel-path="selectedFolder ? selectedFolder.rel_path : ''"
          @select="selectFolder"
          @clear="clearFolderFilter"
        />
      </aside>
      <div class="search-main">
    <header class="hero">
      <h1>Webalbum</h1>
      <p>Query your indexer DB (read-only).</p>
    </header>

    <section class="panel">
      <div v-if="loadedSearchName" class="loaded-indicator">
        <span>Loaded: {{ loadedSearchName }}</span>
        <span v-if="isModified" class="pill">Modified</span>
        <button v-if="isModified" class="inline" type="button" @click="resetToLoaded">
          Reset to loaded
        </button>
      </div>
      <div class="row">
        <label class="tags">
          Tags
          <div class="tag-rows">
            <div v-for="(tag, idx) in form.tags" :key="idx" class="tag-row">
              <select v-model="form.tags[idx].mode">
                <option value="include">AND</option>
                <option value="exclude">AND NOT</option>
              </select>
              <input
                v-model="form.tags[idx].value"
                type="text"
                placeholder="Tag"
                @focus="setActiveTag(idx)"
                @input="onTagInput(idx)"
                @keydown.enter.prevent="runSearch"
              />
              <button type="button" class="tag-remove" @click="clearTagRow(idx)">✕</button>
            </div>
          </div>
          <button type="button" class="tag-add" @click="addTagRow">+ Add tag</button>
          <div v-if="activeTagIndex !== null && suggestions.length" class="suggestions">
            <button
              v-for="item in suggestions"
              :key="item.tag"
              type="button"
              class="suggestion"
              @click="applySuggestion(item.tag)"
            >
              <span class="name">{{ item.tag }}</span>
              <span class="count">{{ item.cnt }}</span>
            </button>
          </div>
        </label>
        <label>
          Tag match
          <select v-model="form.tagMode">
            <option value="ALL">All</option>
            <option value="ANY">Any</option>
          </select>
        </label>
        <label>
          Path contains
          <input v-model.trim="form.path" placeholder="/Trips/" />
        </label>
      </div>
      <div class="row">
        <label>
          Taken
          <select v-model="form.dateOp">
            <option value="after">After</option>
            <option value="before">Before</option>
            <option value="between">Between</option>
          </select>
        </label>
        <label v-if="form.dateOp !== 'between'">
          Date
          <input v-model.trim="form.date" type="text" placeholder="YYYY-MM-DD" />
        </label>
        <label v-else>
          Start
          <input v-model.trim="form.start" type="text" placeholder="YYYY-MM-DD" />
        </label>
        <label v-if="form.dateOp === 'between'">
          End
          <input v-model.trim="form.end" type="text" placeholder="YYYY-MM-DD" />
        </label>
      </div>
      <div class="row">
        <label>
          Sort
          <select v-model="form.sortField">
            <option value="path">Path</option>
            <option value="taken">Taken</option>
          </select>
        </label>
        <label>
          Direction
          <select v-model="form.sortDir">
            <option value="asc">{{ sortDirLabel("asc") }}</option>
            <option value="desc">{{ sortDirLabel("desc") }}</option>
          </select>
        </label>
        <label>
          Type
          <select v-model="form.type">
            <option value="">Any</option>
            <option value="image">Image</option>
            <option value="video">Video</option>
          </select>
        </label>
        <label>
          <span>Only favorites</span>
          <input v-model="form.onlyFavorites" type="checkbox" :disabled="!canFavorite" />
        </label>
        <label>
          Limit
          <input v-model.number="form.limit" type="number" min="1" max="1000" />
        </label>
        <label>
          View
          <select v-model="viewMode">
            <option value="list">List</option>
            <option value="grid">Grid</option>
          </select>
        </label>
      </div>
      <div class="row actions">
        <button @click="runSearch" :disabled="loading">Search</button>
        <span v-if="selectedFolder" class="pill folder-pill" :title="selectedFolder.rel_path">Folder: {{ selectedFolder.rel_path }}</span>
        <button v-if="selectedFolder" class="clear" type="button" @click="clearFolderFilter">Clear folder filter</button>
        <button @click="openSaveModal" :disabled="loading">Save search</button>
        <button @click="clearCriteria" :disabled="loading">Clear search criteria</button>
        <label class="checkbox">
          <input type="checkbox" v-model="debug" />
          Debug SQL
        </label>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <section class="results">
      <div class="meta">
        <span v-if="loading">Loading…</span>
        <span v-else-if="total === null">Results: —</span>
        <span v-else>{{ resultsSummary }}</span>
        <span v-if="savedBanner" class="pill">{{ savedBanner }}</span>
      </div>
      <div class="view-toggle">
        <button
          type="button"
          :class="{ active: viewMode === 'list' }"
          @click="viewMode = 'list'"
        >
          List
        </button>
        <button
          type="button"
          :class="{ active: viewMode === 'grid' }"
          @click="viewMode = 'grid'"
        >
          Grid
        </button>
      </div>
      <div class="pager" v-if="total !== null && total > (form.limit || 50)">
        <button :disabled="page === 1 || loading" @click="prevPage">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <input
          v-model.number="pageInput"
          type="number"
          min="1"
          :max="totalPages"
          placeholder="Go to"
        />
        <button :disabled="loading" @click="jumpToPage">Go</button>
        <button :disabled="page >= totalPages || loading" @click="nextPage">Next</button>
        <button
          class="download"
          :disabled="loading || selectedIds.length === 0 || selectedIds.length > 20"
          @click="downloadSelected"
        >
          Download selected ({{ selectedIds.length }})
        </button>
        <span class="note">Max 20 images per ZIP</span>
        <button
          v-if="selectedIds.length"
          class="clear"
          type="button"
          @click="clearSelection"
        >
          Unselect all
        </button>
      </div>
      <results-list
        v-if="viewMode === 'list'"
        :items="results"
        :offset="offset"
        :selected-ids="selectedIds"
        :can-favorite="canFavorite"
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
        :can-favorite="canFavorite"
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
      <div
        class="pager"
        v-if="total !== null && total <= (form.limit || 50)"
      >
        <button
          class="download"
          :disabled="loading || selectedIds.length === 0 || selectedIds.length > 20"
          @click="downloadSelected"
        >
          Download selected ({{ selectedIds.length }})
        </button>
        <span class="note">Max 20 images per ZIP</span>
        <button
          v-if="selectedIds.length"
          class="clear"
          type="button"
          @click="clearSelection"
        >
          Unselect all
        </button>
      </div>
      <div class="pager" v-if="total !== null && total > (form.limit || 50)">
        <button :disabled="page === 1 || loading" @click="prevPage">Previous</button>
        <span>Page {{ page }} of {{ totalPages }}</span>
        <input
          v-model.number="pageInput"
          type="number"
          min="1"
          :max="totalPages"
          placeholder="Go to"
        />
        <button :disabled="loading" @click="jumpToPage">Go</button>
        <button :disabled="page >= totalPages || loading" @click="nextPage">Next</button>
        <button
          class="download"
          :disabled="loading || selectedIds.length === 0 || selectedIds.length > 20"
          @click="downloadSelected"
        >
          Download selected ({{ selectedIds.length }})
        </button>
        <span class="note">Max 20 images per ZIP</span>
      </div>
      <pre v-if="debugInfo" class="debug">{{ debugInfo }}</pre>
    </section>
      </div>
    </div>
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
    <div v-if="saveOpen" class="modal-backdrop" @click.self="closeSaveModal">
      <div class="modal">
        <h3>Save search</h3>
        <label>
          Name
          <input v-model.trim="saveName" type="text" />
        </label>
        <div class="modal-actions">
          <button class="inline" @click="submitSave(false)" :disabled="loading">Save</button>
          <button class="inline" @click="closeSaveModal" :disabled="loading">Cancel</button>
        </div>
        <p v-if="saveError" class="error">{{ saveError }}</p>
      </div>
    </div>
    <div v-if="replaceOpen" class="modal-backdrop" @click.self="closeReplaceModal">
      <div class="modal">
        <h3>Replace saved search?</h3>
        <p>A saved search with this name already exists. Replace it?</p>
        <div class="modal-actions">
          <button class="inline" @click="submitSave(true)" :disabled="loading">Replace</button>
          <button class="inline" @click="closeReplaceModal" :disabled="loading">Cancel</button>
        </div>
      </div>
    </div>
    <div v-if="toast" class="toast">{{ toast }}</div>
  </div>
</template>

<script>
import ResultsGrid from "../components/ResultsGrid.vue";
import ResultsList from "../components/ResultsList.vue";
import ImageViewer from "../components/ImageViewer.vue";
import VideoViewer from "../components/VideoViewer.vue";
import FolderTree from "../components/FolderTree.vue";

export default {
  name: "SearchPage",
  components: { ResultsGrid, ResultsList, ImageViewer, VideoViewer, FolderTree },
  data() {
    return {
      loading: false,
      error: "",
      debug: false,
      debugInfo: "",
      results: [],
      total: null,
      offset: 0,
      limit: 50,
      form: {
        tags: [{ value: "", mode: "include" }],
        tagMode: "ALL",
        path: "",
        dateOp: "after",
        date: "",
        start: "",
        end: "",
        type: "image",
        onlyFavorites: false,
        sortField: "path",
        sortDir: "asc",
        limit: 50
      },
      page: 1,
      pageInput: null,
      activeTagIndex: null,
      suggestions: [],
      suggestTimer: null,
      selectedIds: [],
      toast: "",
      viewMode: "list",
      viewerOpen: false,
      viewerStartId: 0,
      videoViewerOpen: false,
      videoViewerStartId: 0,
      currentUser: null,
      saveOpen: false,
      replaceOpen: false,
      saveName: "",
      saveError: "",
      savedBanner: "",
      suspendAuto: false,
      loadedSearchId: null,
      loadedSearchName: "",
      loadedQuery: null,
      loadedSnapshot: "",
      selectedFolder: null
    };
  },
  mounted() {
    const prefs = window.__wa_prefs || null;
    this.applyPrefs(prefs);
    if (!prefs) {
      this.viewMode = window.matchMedia("(min-width: 1024px)").matches ? "grid" : "list";
    }
    this.currentUser = window.__wa_current_user || null;
    this.restoreFolderFilter();
    this.applyLoadFromRoute();
    window.addEventListener("wa-auth-changed", this.onUserChanged);
    window.addEventListener("wa-prefs-changed", this.onPrefsChanged);
  },
  beforeUnmount() {
    window.removeEventListener("wa-auth-changed", this.onUserChanged);
    window.removeEventListener("wa-prefs-changed", this.onPrefsChanged);
  },
  methods: {
    onUserChanged(event) {
      this.currentUser = event.detail || null;
    },
    onPrefsChanged(event) {
      this.applyPrefs(event.detail || null);
    },
    applyPrefs(prefs) {
      if (!prefs) {
        return;
      }
      this.suspendAuto = true;
      this.form.limit = prefs.page_size || 50;
      this.viewMode = prefs.default_view || "grid";
      const sort = prefs.sort_mode || "name_az";
      if (sort === "name_az") {
        this.form.sortField = "path";
        this.form.sortDir = "asc";
      } else if (sort === "name_za") {
        this.form.sortField = "path";
        this.form.sortDir = "desc";
      } else if (sort === "date_new_old") {
        this.form.sortField = "taken";
        this.form.sortDir = "asc";
      } else if (sort === "date_old_new") {
        this.form.sortField = "taken";
        this.form.sortDir = "desc";
      }
      this.suspendAuto = false;
    },
    applyLoadFromRoute() {
      const loadId = this.$route?.query?.load;
      if (!loadId) {
        return;
      }
      const autoRun = this.$route?.query?.run === "1";
      this.fetchSavedSearch(loadId, autoRun);
    },
    async fetchSavedSearch(id, autoRun) {
      try {
        const res = await fetch(`/api/saved-searches/${id}`);
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.showToast(data.error || "Failed to load saved search");
          return;
        }
        const query = data.query_json || data.query;
        if (!query || typeof query !== "object") {
          this.showToast("Saved search is invalid");
          return;
        }
        this.applyQuery(query, data.name || "", { autoRun, id: data.id });
      } catch (err) {
        this.showToast("Failed to load saved search");
      }
    },
    applyQuery(query, name, options = {}) {
      const { form, page, pageInput, folder } = this.builderFromQuery(query);
      this.suspendAuto = true;
      this.form = form;
      this.page = page;
      this.selectedFolder = folder || null;
      this.pageInput = pageInput;
      this.activeTagIndex = null;
      this.suggestions = [];
      this.suspendAuto = false;
      this.loadedSearchId = options.id || null;
      this.loadedSearchName = name || "";
      this.loadedQuery = JSON.parse(JSON.stringify(query));
      this.loadedSnapshot = this.snapshotFromQuery(this.loadedQuery);
      this.persistFolderFilter();
      this.savedBanner = name ? `Loaded from saved search: ${name}` : "";
      if (options.autoRun) {
        this.$nextTick(() => {
          this.runSearch();
        });
      }
    },
    builderFromQuery(query) {
      const where = query.where && typeof query.where === "object" ? query.where : {};
      const builder = this.builderFromWhere(where);
      const folder = builder.folder || null;
      builder.form.sortField = query.sort && query.sort.field ? query.sort.field : "path";
      builder.form.sortDir = query.sort && query.sort.dir ? query.sort.dir : "asc";
      builder.form.limit = Number.isFinite(query.limit) ? query.limit : 50;
      const limit = builder.form.limit || 50;
      const offset = Number.isFinite(query.offset) ? query.offset : 0;
      builder.page = Math.floor(offset / limit) + 1;
      builder.pageInput = builder.page;
      builder.folder = folder;
      return builder;
    },
    builderFromWhere(where) {
      const items = Array.isArray(where.items) ? where.items : [];
      const includeGroup = items.find(
        (item) =>
          item &&
          item.group &&
          Array.isArray(item.items) &&
          item.items.every(
            (rule) => rule && rule.field === "tag" && rule.op === "is" && typeof rule.value === "string"
          )
      );
      const includeTags = includeGroup ? includeGroup.items.map((rule) => rule.value) : [];
      const excludeTags = items
        .filter(
          (item) =>
            item &&
            item.field === "tag" &&
            item.op === "is_not" &&
            typeof item.value === "string"
        )
        .map((item) => item.value);
      const pathItem = items.find((item) => item && item.field === "path");
      const typeItem = items.find((item) => item && item.field === "type" && item.op === "is");
      const takenItem = items.find((item) => item && item.field === "taken");

      const folderRelPath = typeof where.folder_rel_path === "string" ? where.folder_rel_path.trim() : "";
      const folderId = Number.isInteger(where.folder_id) ? where.folder_id : null;

      const form = {
        tags: [],
        tagMode: includeGroup && includeGroup.group === "ANY" ? "ANY" : "ALL",
        path: pathItem && typeof pathItem.value === "string" ? pathItem.value : "",
        dateOp: "after",
        date: "",
        start: "",
        end: "",
        type: typeItem && typeof typeItem.value === "string" ? typeItem.value : "",
        onlyFavorites: !!where.only_favorites,
        sortField: "path",
        sortDir: "asc",
        limit: 50
      };

      includeTags.forEach((tag) => form.tags.push({ value: tag, mode: "include" }));
      excludeTags.forEach((tag) => form.tags.push({ value: tag, mode: "exclude" }));
      if (form.tags.length === 0) {
        form.tags = [{ value: "", mode: "include" }];
      }

      if (takenItem && typeof takenItem.op === "string") {
        if (takenItem.op === "between" && Array.isArray(takenItem.value)) {
          form.dateOp = "between";
          form.start = takenItem.value[0] || "";
          form.end = takenItem.value[1] || "";
        } else if (typeof takenItem.value === "string") {
          form.dateOp = takenItem.op;
          form.date = takenItem.value;
        }
      }

      const folder = folderRelPath
        ? {
            id: folderId,
            rel_path: folderRelPath,
            name: folderRelPath.split("/").filter(Boolean).pop() || folderRelPath
          }
        : null;

      return { form, page: 1, pageInput: 1, folder };
    },
    whereFromBuilder() {
      const items = [];
      const includeTags = this.form.tags
        .filter((t) => t.mode === "include")
        .map((t) => t.value.trim())
        .filter(Boolean);
      const excludeTags = this.form.tags
        .filter((t) => t.mode === "exclude")
        .map((t) => t.value.trim())
        .filter(Boolean);

      if (includeTags.length > 0) {
        items.push({
          group: this.form.tagMode,
          items: includeTags.map((tag) => ({ field: "tag", op: "is", value: tag }))
        });
      }
      if (excludeTags.length > 0) {
        excludeTags.forEach((tag) => {
          items.push({ field: "tag", op: "is_not", value: tag });
        });
      }
      if (this.form.path) {
        items.push({ field: "path", op: "contains", value: this.form.path });
      }
      if (this.form.dateOp === "between") {
        if (this.form.start && this.form.end) {
          items.push({
            field: "taken",
            op: "between",
            value: [this.form.start, this.form.end]
          });
        }
      } else if (this.form.date) {
        items.push({ field: "taken", op: this.form.dateOp, value: this.form.date });
      }
      if (this.form.type) {
        items.push({ field: "type", op: "is", value: this.form.type });
      }

      const where = {
        group: "ALL",
        items,
        only_favorites: this.form.onlyFavorites
      };
      if (this.selectedFolder && this.selectedFolder.id) {
        // Tree-selected folder should filter direct files only.
        where.folder_id = this.selectedFolder.id;
      } else if (this.selectedFolder && this.selectedFolder.rel_path) {
        // Backward-compatible fallback for saved payloads.
        where.folder_rel_path = this.selectedFolder.rel_path;
      }
      return where;
    },
    snapshotFromQuery(query) {
      const snapshot = {
        where: query.where || {},
        sort: query.sort || {},
        limit: Number.isFinite(query.limit) ? query.limit : 50
      };
      return JSON.stringify(snapshot);
    },
    snapshotFromBuilder() {
      const query = {
        where: this.whereFromBuilder(),
        sort: { field: this.form.sortField, dir: this.form.sortDir },
        limit: this.form.limit || 50
      };
      return this.snapshotFromQuery(query);
    },
    resetToLoaded() {
      if (!this.loadedQuery) {
        return;
      }
      this.applyQuery(this.loadedQuery, this.loadedSearchName, { autoRun: false });
    },
    buildQuery() {
      const dateRe = /^\d{4}-\d{2}-\d{2}$/;
      const normalizeDate = (value) =>
        value
          .trim()
          .replace(/[\u2010-\u2015\u2212]/g, "-");

      const where = this.whereFromBuilder();
      if (this.form.dateOp === "between") {
        if (this.form.start && this.form.end) {
          const start = normalizeDate(this.form.start);
          const end = normalizeDate(this.form.end);
          this.form.start = start;
          this.form.end = end;
          if (!dateRe.test(start) || !dateRe.test(end)) {
            this.error = "Date must be YYYY-MM-DD";
            return null;
          }
          where.items = where.items.filter((item) => item.field !== "taken");
          where.items.push({ field: "taken", op: "between", value: [start, end] });
        }
      } else if (this.form.date) {
        const date = normalizeDate(this.form.date);
        this.form.date = date;
        if (!dateRe.test(date)) {
          this.error = "Date must be YYYY-MM-DD";
          return null;
        }
        where.items = where.items.filter((item) => item.field !== "taken");
        where.items.push({ field: "taken", op: this.form.dateOp, value: date });
      }

      return {
        where,
        sort: { field: this.form.sortField, dir: this.form.sortDir },
        limit: this.form.limit || 50,
        offset: (this.page - 1) * (this.form.limit || 50)
      };
    },
    openSaveModal() {
      if (!this.currentUser) {
        this.showToast("Login required to save searches");
        return;
      }
      const suggested = this.suggestedName();
      this.saveName = suggested;
      this.saveError = "";
      this.replaceOpen = false;
      this.saveOpen = true;
    },
    closeSaveModal() {
      this.saveOpen = false;
      this.saveError = "";
    },
    closeReplaceModal() {
      this.replaceOpen = false;
    },
    suggestedName() {
      const parts = [];
      const includeTags = this.form.tags
        .filter((t) => t.mode === "include")
        .map((t) => t.value.trim())
        .filter(Boolean);
      const excludeTags = this.form.tags
        .filter((t) => t.mode === "exclude")
        .map((t) => t.value.trim())
        .filter(Boolean);
      includeTags.forEach((tag) => parts.push(tag));
      excludeTags.forEach((tag) => parts.push(`NOT ${tag}`));

      if (this.form.dateOp === "between" && this.form.start && this.form.end) {
        parts.push(`from ${this.form.start} to ${this.form.end}`);
      } else if (this.form.dateOp === "after" && this.form.date) {
        parts.push(`after ${this.form.date}`);
      } else if (this.form.dateOp === "before" && this.form.date) {
        parts.push(`before ${this.form.date}`);
      }
      if (this.form.type) {
        parts.push(`type ${this.form.type}`);
      }
      if (this.form.path) {
        parts.push(`path ${this.form.path}`);
      }
      if (this.selectedFolder && this.selectedFolder.rel_path) {
        parts.push(`in ${this.selectedFolder.rel_path}`);
      }
      if (parts.length) {
        return parts.join(" · ");
      }
      const now = new Date();
      const stamp = now.toISOString().slice(0, 16).replace("T", " ");
      return `Search ${stamp}`;
    },
    async submitSave(replace) {
      const query = this.buildQuery();
      if (!query) {
        this.saveError = this.error || "Invalid query";
        return;
      }
      const name = this.saveName.trim();
      if (!name) {
        this.saveError = "Name is required";
        return;
      }
      this.loading = true;
      this.saveError = "";
      try {
        const res = await fetch("/api/saved-searches", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ name, query, replace })
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (res.status === 409 && data.error === "exists") {
          this.saveOpen = false;
          this.replaceOpen = true;
          return;
        }
        if (!res.ok) {
          this.saveError = data.message || data.error || "Failed to save search";
          return;
        }
        this.saveOpen = false;
        this.replaceOpen = false;
        this.showToast("Saved");
      } catch (err) {
        this.saveError = "Failed to save search";
      } finally {
        this.loading = false;
      }
    },

    selectFolder(folder) {
      if (!folder || !folder.rel_path) {
        return;
      }
      this.selectedFolder = {
        id: folder.id || null,
        rel_path: folder.rel_path,
        name: folder.name || folder.rel_path
      };
      this.persistFolderFilter();
      this.page = 1;
      this.runSearch();
    },
    clearFolderFilter() {
      this.selectedFolder = null;
      this.persistFolderFilter();
      this.page = 1;
      this.runSearch();
    },
    persistFolderFilter() {
      try {
        if (this.selectedFolder && this.selectedFolder.rel_path) {
          window.localStorage.setItem("wa_folder_filter", JSON.stringify(this.selectedFolder));
        } else {
          window.localStorage.removeItem("wa_folder_filter");
        }
      } catch (_err) {
        // ignore storage errors
      }
    },
    restoreFolderFilter() {
      try {
        const raw = window.localStorage.getItem("wa_folder_filter");
        if (!raw) {
          return;
        }
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed.rel_path !== "string" || parsed.rel_path.trim() === "") {
          return;
        }
        this.selectedFolder = {
          id: Number.isInteger(parsed.id) ? parsed.id : null,
          rel_path: parsed.rel_path.trim(),
          name: parsed.name || parsed.rel_path.split("/").filter(Boolean).pop() || parsed.rel_path
        };
      } catch (_err) {
        // ignore storage errors
      }
    },
    addTagRow() {
      this.form.tags.push({ value: "", mode: "include" });
    },
    clearTagRow(idx) {
      this.form.tags[idx].value = "";
      this.form.tags[idx].mode = "include";
      if (this.activeTagIndex === idx) {
        this.activeTagIndex = null;
        this.suggestions = [];
      }
    },
    setActiveTag(idx) {
      this.activeTagIndex = idx;
      this.onTagInput(idx);
    },
    onTagInput(idx) {
      if (this.suggestTimer) {
        clearTimeout(this.suggestTimer);
      }
      const value = this.form.tags[idx]?.value || "";
      if (value.trim().length < 2) {
        this.suggestions = [];
        return;
      }
      this.suggestTimer = setTimeout(() => {
        this.fetchSuggestions(value);
      }, 200);
    },
    async fetchSuggestions(query) {
      try {
        const qs = new URLSearchParams();
        qs.set("q", query);
        qs.set("limit", "12");
        const res = await fetch(`/api/tags?${qs.toString()}`);
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          return;
        }
        if (Array.isArray(data)) {
          this.suggestions = data;
        }
      } catch (err) {
        // ignore
      }
    },
    applySuggestion(tag) {
      if (this.activeTagIndex === null) {
        return;
      }
      this.form.tags[this.activeTagIndex].value = tag;
      this.suggestions = [];
    },
    async runSearch() {
      this.loading = true;
      this.error = "";
      this.debugInfo = "";
      try {
        const query = this.buildQuery();
        if (!query) {
          this.loading = false;
          return;
        }
        const res = await fetch(`/api/search${this.debug ? "?debug=1" : ""}`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(query)
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Search failed";
          this.results = [];
          return;
        }
        if (Array.isArray(data.items)) {
          this.results = data.items;
          this.total = typeof data.total === "number" ? data.total : data.items.length;
          this.offset = typeof data.offset === "number" ? data.offset : 0;
          this.limit = typeof data.limit === "number" ? data.limit : this.form.limit || 50;
          this.pageInput = this.page;
          this.debugInfo = data.debug ? JSON.stringify(data.debug, null, 2) : "";
        } else {
          this.results = [];
          this.total = 0;
          this.pageInput = this.page;
        }
        this.selectedIds = [];
        this.viewerOpen = false;
        this.videoViewerOpen = false;
        if (!this.canFavorite) {
          this.form.onlyFavorites = false;
        }
      } catch (err) {
        this.error = err.message || String(err);
        this.results = [];
      } finally {
        this.loading = false;
      }
    },
    nextPage() {
      if (this.page < this.totalPages) {
        this.page += 1;
        this.runSearch();
      }
    },
    prevPage() {
      if (this.page > 1) {
        this.page -= 1;
        this.runSearch();
      }
    },
    jumpToPage() {
      const target = Number(this.pageInput);
      if (!Number.isFinite(target)) {
        return;
      }
      const clamped = Math.min(Math.max(1, target), this.totalPages);
      if (clamped !== this.page) {
        this.page = clamped;
        this.runSearch();
      }
    },
    formatTs(ts) {
      const d = new Date(ts * 1000);
      return d.toISOString().slice(0, 10);
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
    fileName(path) {
      if (!path) {
        return "";
      }
      const parts = path.split("/");
      return parts[parts.length - 1];
    },
    async copyLink(id) {
      const text = this.fileUrl(id);
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const input = document.createElement("input");
          input.value = text;
          document.body.appendChild(input);
          input.select();
          document.execCommand("copy");
          document.body.removeChild(input);
        }
        this.showToast("Copied!");
      } catch (err) {
        this.showToast("Copy failed");
      }
    },
    async downloadSelected() {
      if (this.selectedIds.length === 0) {
        this.showToast("Please select images first (max 20)");
        return;
      }
      if (this.selectedIds.length > 20) {
        this.showToast("More than 20 images selected, please unselect some");
        return;
      }
      try {
        const res = await fetch("/api/download", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ ids: this.selectedIds })
        });
        if (this.handleAuthError(res)) {
          return;
        }
        if (!res.ok) {
          const data = await res.json();
          this.showToast(data.error || "Download failed");
          return;
        }
        const blob = await res.blob();
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        const disposition = res.headers.get("Content-Disposition") || "";
        const match = disposition.match(/filename=\"?([^\";]+)\"?/);
        link.download = match ? match[1] : "webalbum-selected.zip";
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
      } catch (err) {
        this.showToast("Download failed");
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
        await this.runSearch();
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
    handleAuthError(res) {
      if (res.status === 401 || res.status === 403) {
        window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
        this.$router.push("/login");
        return true;
      }
      return false;
    },
    async toggleFavorite(fileId) {
      if (!this.canFavorite) {
        this.showToast("Login required to use favorites");
        return;
      }
      const row = this.results.find((r) => r.id === fileId);
      if (!row) {
        return;
      }
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
      } catch (err) {
        row.is_favorite = prev;
        this.showToast("Failed to toggle favorite");
      }
    },
    clearSelection() {
      this.selectedIds = [];
    },
    clearCriteria() {
      const prefs = window.__wa_prefs || null;
      const pageSize = prefs && prefs.page_size ? prefs.page_size : 50;
      this.suspendAuto = true;
      this.form = {
        tags: [{ value: "", mode: "include" }],
        tagMode: "ALL",
        path: "",
        dateOp: "after",
        date: "",
        start: "",
        end: "",
        type: "image",
        onlyFavorites: false,
        sortField: "path",
        sortDir: "asc",
        limit: pageSize
      };
      this.page = 1;
      this.pageInput = 1;
      this.activeTagIndex = null;
      this.suggestions = [];
      this.error = "";
      this.savedBanner = "";
      this.loadedSearchId = null;
      this.loadedSearchName = "";
      this.loadedQuery = null;
      this.loadedSnapshot = "";
      this.selectedFolder = null;
      this.persistFolderFilter();
      this.suspendAuto = false;
      this.runSearch();
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
      if (row.type !== "image") {
        this.showToast("Preview not supported for this file type");
        return;
      }
      this.videoViewerOpen = false;
      this.viewerStartId = id;
      this.viewerOpen = true;
    },
    closeViewer() {
      this.viewerOpen = false;
    },
    closeVideoViewer() {
      this.videoViewerOpen = false;
    },
    async onItemTrashed() {
      this.viewerOpen = false;
      this.videoViewerOpen = false;
      this.showToast("Moved to Trash");
      this.selectedIds = [];
      await this.runSearch();
    },
    sortDirLabel(dir) {
      if (this.form.sortField === "taken") {
        return dir === "asc" ? "New-Old" : "Old-New";
      }
      return dir === "asc" ? "A-Z" : "Z-A";
    }
  },
  watch: {
    "form.dateOp"(next, prev) {
      if (next === "between" && prev === "after") {
        if (this.form.date && !this.form.start) {
          this.form.start = this.form.date;
        }
      }
    },
    "$route.query.load"() {
      this.applyLoadFromRoute();
    },
    "form.sortField"() {
      if (this.suspendAuto) {
        return;
      }
      this.page = 1;
      this.runSearch();
    },
    "form.sortDir"() {
      if (this.suspendAuto) {
        return;
      }
      this.page = 1;
      this.runSearch();
    },
    "form.limit"() {
      if (this.suspendAuto) {
        return;
      }
      this.page = 1;
      this.runSearch();
    },
    "form.onlyFavorites"() {
      if (this.suspendAuto) {
        return;
      }
      this.page = 1;
      this.runSearch();
    },
    viewMode() {}
  },
  computed: {
    resultsSummary() {
      if (this.total === null) {
        return "Results: —";
      }
      const total = Number(this.total || 0);
      if (total <= 0) {
        return "Results: 0 of 0 (0 images)";
      }
      const perPage = Math.max(1, Number(this.form.limit || 50));
      const currentPage = Math.max(1, Number(this.page || 1));
      const start = ((currentPage - 1) * perPage) + 1;
      const end = Math.min(currentPage * perPage, total);
      return `Results: ${start}-${end} of ${total} (${total} images)`;
    },
    totalPages() {
      if (this.total === null || this.total === 0) {
        return 1;
      }
      const perPage = this.form.limit || 50;
      return Math.max(1, Math.ceil(this.total / perPage));
    },
    canFavorite() {
      return !!this.currentUser;
    },
    isAdmin() {
      return !!(this.currentUser && this.currentUser.is_admin);
    },
    isModified() {
      if (!this.loadedSnapshot) {
        return false;
      }
      return this.snapshotFromBuilder() !== this.loadedSnapshot;
    }
  }
};
</script>


<style scoped>
.search-layout {
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}
.folders-sidebar {
  position: sticky;
  top: 12px;
}
.search-main {
  min-width: 0;
}
.folder-pill {
  max-width: 420px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (max-width: 980px) {
  .search-layout {
    grid-template-columns: 1fr;
  }
  .folders-sidebar {
    position: static;
  }
}
</style>
