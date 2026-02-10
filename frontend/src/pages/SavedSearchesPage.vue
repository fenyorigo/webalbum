<template>
  <div class="page">
    <header class="hero">
      <h1>Saved searches</h1>
      <p>Manage your saved search presets.</p>
    </header>

    <section class="panel">
      <div class="row">
        <button class="inline" :disabled="loading" @click="fetchSaved">Refresh</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <section class="results">
      <div class="meta">
        <span v-if="loading">Loading…</span>
        <span v-else-if="rows.length === 0">No saved searches yet.</span>
      </div>
      <table class="tags-table" v-if="rows.length">
        <thead>
          <tr>
            <th>Name</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td class="tag">{{ row.name }}</td>
            <td>{{ formatDate(row.updated_at) }}</td>
            <td class="actions">
              <button class="inline" @click="runSaved(row)">Run</button>
              <button class="inline" @click="loadSaved(row)">Load</button>
              <button class="inline" @click="openRename(row)">Rename</button>
              <button class="inline" @click="openDelete(row)">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>

    <div v-if="renameOpen" class="modal-backdrop" @click.self="closeRename">
      <div class="modal">
        <h3>Rename saved search</h3>
        <label>
          Name
          <input v-model.trim="renameName" type="text" />
        </label>
        <div class="modal-actions">
          <button class="inline" @click="submitRename" :disabled="loading">Save</button>
          <button class="inline" @click="closeRename" :disabled="loading">Cancel</button>
        </div>
        <p v-if="modalError" class="error">{{ modalError }}</p>
      </div>
    </div>

    <div v-if="deleteOpen" class="modal-backdrop" @click.self="closeDelete">
      <div class="modal">
        <h3>Delete saved search</h3>
        <p>Delete “{{ deleteTarget && deleteTarget.name }}”?</p>
        <div class="modal-actions">
          <button class="inline" @click="confirmDelete" :disabled="loading">Delete</button>
          <button class="inline" @click="closeDelete" :disabled="loading">Cancel</button>
        </div>
      </div>
    </div>

    <div v-if="toast" class="toast">{{ toast }}</div>
  </div>
</template>

<script>
export default {
  name: "SavedSearchesPage",
  data() {
    return {
      rows: [],
      loading: false,
      error: "",
      toast: "",
      renameOpen: false,
      renameTarget: null,
      renameName: "",
      deleteOpen: false,
      deleteTarget: null,
      modalError: ""
    };
  },
  mounted() {
    this.fetchSaved();
  },
  methods: {
    async fetchSaved() {
      this.loading = true;
      this.error = "";
      try {
        const res = await fetch("/api/saved-searches");
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load saved searches";
          this.rows = [];
          return;
        }
        this.rows = Array.isArray(data) ? data : [];
      } catch (err) {
        this.error = err.message || String(err);
      } finally {
        this.loading = false;
      }
    },
    formatDate(value) {
      if (!value) return "";
      return String(value).slice(0, 10);
    },
    async runSaved(row) {
      this.$router.push({ path: "/", query: { load: String(row.id), run: "1" } });
    },
    loadSaved(row) {
      this.$router.push({ path: "/", query: { load: String(row.id) } });
    },
    openRename(row) {
      this.renameTarget = row;
      this.renameName = row.name;
      this.modalError = "";
      this.renameOpen = true;
    },
    closeRename() {
      this.renameOpen = false;
      this.renameTarget = null;
      this.renameName = "";
      this.modalError = "";
    },
    async submitRename() {
      if (!this.renameTarget) {
        return;
      }
      const name = this.renameName.trim();
      if (!name) {
        this.modalError = "Name is required";
        return;
      }
      this.loading = true;
      this.modalError = "";
      try {
        const res = await fetch(`/api/saved-searches/${this.renameTarget.id}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ name })
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.modalError = data.message || data.error || "Rename failed";
          return;
        }
        this.renameTarget.name = name;
        this.closeRename();
        await this.fetchSaved();
      } catch (err) {
        this.modalError = "Rename failed";
      } finally {
        this.loading = false;
      }
    },
    openDelete(row) {
      this.deleteTarget = row;
      this.deleteOpen = true;
    },
    closeDelete() {
      this.deleteOpen = false;
      this.deleteTarget = null;
    },
    async confirmDelete() {
      if (!this.deleteTarget) {
        return;
      }
      this.loading = true;
      try {
        const res = await fetch(`/api/saved-searches/${this.deleteTarget.id}`, {
          method: "DELETE"
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.showToast(data.error || "Delete failed");
          return;
        }
        this.rows = this.rows.filter((row) => row.id !== this.deleteTarget.id);
        this.closeDelete();
      } catch (err) {
        this.showToast("Delete failed");
      } finally {
        this.loading = false;
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
    }
  }
};
</script>
