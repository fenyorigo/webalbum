<template>
  <div class="page">
    <header class="hero">
      <h1>Tag Admin</h1>
      <p>Control tag visibility for global and personal scope.</p>
    </header>

    <section class="panel">
      <div class="row">
        <label>
          Search
          <input v-model.trim="query" placeholder="Filter tags..." />
        </label>
        <label>
          Limit
          <input v-model.number="limit" type="number" min="10" max="200" />
        </label>
        <label v-if="isAdmin" class="checkbox">
          <input type="checkbox" v-model="revealHidden" />
          Reveal hidden tags
        </label>
        <button class="inline" :disabled="loading" @click="fetchTags">Refresh</button>
        <button class="inline" :disabled="loading || !hasChanges" @click="saveAll">Save</button>
        <button class="inline" :disabled="loading" @click="cancelChanges">Cancel</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <section class="results">
      <div class="meta">
        <span v-if="loading">Loading…</span>
        <span v-else-if="total === null">Results: —</span>
        <span v-else>Results: {{ rows.length }} of {{ total }}</span>
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
      <table class="tags-table" v-if="rows.length">
        <thead>
          <tr>
            <th>Tag</th>
            <th>Variants</th>
            <th v-if="isAdmin">Enabled (Global)</th>
            <th>Enabled<span v-if="isAdmin"> (Personal)</span></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.tag">
            <td class="tag">{{ row.tag }}</td>
            <td class="variants">
              <span class="dots" :title="variantsTitle(row.variants)">
                {{ variantsDots(row.variants) }}
              </span>
            </td>
            <td v-if="isAdmin">
              <input type="checkbox" v-model="row.enabled_global" @change="markDirty(row, 'global')" />
            </td>
            <td>
              <input type="checkbox" v-model="row.enabled_personal" @change="markDirty(row, 'personal')" />
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<script>
export default {
  name: "TagsPage",
  data() {
    return {
      query: "",
      limit: 50,
      page: 1,
      pageInput: null,
      rows: [],
      total: null,
      loading: false,
      error: "",
      dirty: {},
      original: {},
      isAdmin: false,
      revealHidden: false
    };
  },
  mounted() {
    const current = window.__wa_current_user || null;
    this.isAdmin = !!(current && current.is_admin);
    this.fetchTags();
  },
  computed: {
    totalPages() {
      if (this.total === null || this.total === 0) {
        return 1;
      }
      return Math.max(1, Math.ceil(this.total / this.limit));
    },
    hasChanges() {
      return Object.keys(this.dirty).length > 0;
    }
  },
  methods: {
    async fetchTags() {
      this.loading = true;
      this.error = "";
      try {
        const offset = (this.page - 1) * this.limit;
        const qs = new URLSearchParams();
        if (this.query) {
          qs.set("q", this.query);
        }
        if (this.isAdmin && this.revealHidden) {
          qs.set("reveal_hidden", "1");
        }
        qs.set("limit", String(this.limit));
        qs.set("offset", String(offset));
        const res = await fetch(`/api/tags/list?${qs.toString()}`);
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load tags";
          this.rows = [];
          this.total = null;
          return;
        }

        this.isAdmin = !!data.is_admin;
        this.rows = (data.rows || []).map((row) => ({
          ...row,
          enabled_global: !!row.enabled_global,
          enabled_personal: !!row.enabled_personal
        }));
        this.total = typeof data.total === "number" ? data.total : 0;
        this.pageInput = this.page;
        this.dirty = {};
        this.original = {};
        this.rows.forEach((row) => {
          this.original[row.tag] = {
            enabled_global: !!row.enabled_global,
            enabled_personal: !!row.enabled_personal
          };
        });
      } catch (err) {
        this.error = err.message || String(err);
      } finally {
        this.loading = false;
      }
    },
    markDirty(row) {
      const original = this.original[row.tag];
      if (!original) {
        return;
      }
      const changed =
        (!!row.enabled_global !== !!original.enabled_global) ||
        (!!row.enabled_personal !== !!original.enabled_personal);
      if (changed) {
        this.dirty[row.tag] = true;
      } else {
        delete this.dirty[row.tag];
      }
    },
    variantsDots(count) {
      if (count >= 3) {
        return "●●●";
      }
      if (count === 2) {
        return "●●○";
      }
      return "●○○";
    },
    variantsTitle(count) {
      if (count >= 3) {
        return "IPTC keyword, XMP subject, Face/person region";
      }
      if (count === 2) {
        return "IPTC keyword, XMP subject";
      }
      return "IPTC keyword";
    },
    async saveAll() {
      const tags = Object.keys(this.dirty);
      if (tags.length === 0) {
        this.$router.push("/");
        return;
      }

      this.loading = true;
      this.error = "";
      try {
        const requests = [];
        tags.forEach((tag) => {
          const row = this.rows.find((r) => r.tag === tag);
          const original = this.original[tag];
          if (!row || !original) {
            return;
          }

          if (this.isAdmin && !!row.enabled_global !== !!original.enabled_global) {
            requests.push(
              fetch("/api/tags/prefs", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  tag,
                  scope: "global",
                  enabled: row.enabled_global ? 1 : 0
                })
              })
            );
          }

          if (!!row.enabled_personal !== !!original.enabled_personal) {
            requests.push(
              fetch("/api/tags/prefs", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                  tag,
                  scope: "personal",
                  enabled: row.enabled_personal ? 1 : 0
                })
              })
            );
          }
        });

        for (const req of requests) {
          const res = await req;
          if (this.handleAuthError(res)) {
            return;
          }
          if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(data.error || "Failed to save tag settings");
          }
        }
        this.$router.push("/");
      } catch (err) {
        this.error = err.message || String(err);
      } finally {
        this.loading = false;
      }
    },
    cancelChanges() {
      this.$router.push("/");
    },
    nextPage() {
      if (this.page < this.totalPages) {
        this.page += 1;
        this.fetchTags();
      }
    },
    prevPage() {
      if (this.page > 1) {
        this.page -= 1;
        this.fetchTags();
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
        this.fetchTags();
      }
    },
    handleAuthError(res) {
      if (res.status === 401 || res.status === 403) {
        window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
        this.$router.push("/login");
        return true;
      }
      return false;
    }
  },
  watch: {
    query() {
      this.page = 1;
      this.fetchTags();
    },
    limit() {
      this.page = 1;
      this.fetchTags();
    },
    revealHidden() {
      if (this.isAdmin) {
        this.page = 1;
        this.fetchTags();
      }
    }
  }
};
</script>
