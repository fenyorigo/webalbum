<template>
  <div class="page">
    <header class="hero">
      <h1>Webalbum</h1>
      <p>Query your indexer DB (read-only).</p>
    </header>

    <section class="panel">
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
          Limit
          <input v-model.number="form.limit" type="number" min="1" max="1000" />
        </label>
      </div>
      <div class="row actions">
        <button @click="runSearch" :disabled="loading">Search</button>
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
        <span v-else>Results: {{ results.length }} of {{ total }}</span>
      </div>
      <div class="pager" v-if="total !== null">
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
      </div>
      <ul>
        <li v-for="row in results" :key="row.id">
          <code>{{ row.path }}</code>
          <span class="pill">{{ row.type }}</span>
          <span v-if="row.taken_ts" class="ts">{{ formatTs(row.taken_ts) }}</span>
        </li>
      </ul>
      <div class="pager" v-if="total !== null">
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
      </div>
      <pre v-if="debugInfo" class="debug">{{ debugInfo }}</pre>
    </section>
  </div>
</template>

<script>
export default {
  name: "SearchPage",
  data() {
    return {
      loading: false,
      error: "",
      debug: false,
      debugInfo: "",
      results: [],
      total: null,
      form: {
        tags: [{ value: "", mode: "include" }],
        tagMode: "ALL",
        path: "",
        dateOp: "after",
        date: "",
        start: "",
        end: "",
        type: "image",
        sortField: "path",
        sortDir: "asc",
        limit: 200
      },
      page: 1,
      pageInput: null,
      activeTagIndex: null,
      suggestions: [],
      suggestTimer: null
    };
  },
  methods: {
    buildQuery() {
      const items = [];
      const dateRe = /^\d{4}-\d{2}-\d{2}$/;
      const normalizeDate = (value) =>
        value
          .trim()
          .replace(/[\u2010-\u2015\u2212]/g, "-");

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
          const start = normalizeDate(this.form.start);
          const end = normalizeDate(this.form.end);
          this.form.start = start;
          this.form.end = end;
          if (!dateRe.test(start) || !dateRe.test(end)) {
            this.error = "Date must be YYYY-MM-DD";
            return null;
          }
          items.push({
            field: "taken",
            op: "between",
            value: [start, end]
          });
        }
      } else if (this.form.date) {
        const date = normalizeDate(this.form.date);
        this.form.date = date;
        if (!dateRe.test(date)) {
          this.error = "Date must be YYYY-MM-DD";
          return null;
        }
        items.push({ field: "taken", op: this.form.dateOp, value: date });
      }
      if (this.form.type) {
        items.push({ field: "type", op: "is", value: this.form.type });
      }

      return {
        where: {
          group: "ALL",
          items: items.length ? items : [{ field: "type", op: "is", value: "image" }]
        },
        sort: { field: this.form.sortField, dir: this.form.sortDir },
        limit: this.form.limit || 200,
        offset: (this.page - 1) * (this.form.limit || 200)
      };
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
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Search failed";
          this.results = [];
          return;
        }
        if (Array.isArray(data.rows)) {
          this.results = data.rows;
          this.total = typeof data.total === "number" ? data.total : data.rows.length;
          this.pageInput = this.page;
          this.debugInfo = data.debug ? JSON.stringify(data.debug, null, 2) : "";
        } else {
          this.results = data;
          this.total = Array.isArray(data) ? data.length : 0;
          this.pageInput = this.page;
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
    sortDirLabel(dir) {
      if (this.form.sortField === "taken") {
        return dir === "asc" ? "New-Old" : "Old-New";
      }
      return dir === "asc" ? "A-Z" : "Z-A";
    }
  },
  watch: {
    "form.sortField"() {
      this.page = 1;
      this.runSearch();
    },
    "form.sortDir"() {
      this.page = 1;
      this.runSearch();
    },
    "form.limit"() {
      this.page = 1;
      this.runSearch();
    }
  },
  computed: {
    totalPages() {
      if (this.total === null || this.total === 0) {
        return 1;
      }
      const perPage = this.form.limit || 200;
      return Math.max(1, Math.ceil(this.total / perPage));
    }
  }
};
</script>
