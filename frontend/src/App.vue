<template>
  <div id="app">
    <nav class="top">
      <div class="brand">
        Webalbum
        <span class="version">v1.1.2</span>
      </div>
      <div class="links" v-if="currentUser">
        <router-link to="/" class="link" active-class="active" exact-active-class="active">Search</router-link>
        <router-link to="/tags" class="link" active-class="active">Tags</router-link>
        <router-link to="/favorites" class="link" active-class="active">My Favorites</router-link>
        <router-link to="/saved-searches" class="link" active-class="active">Saved searches</router-link>
        <router-link to="/profile" class="link" active-class="active">My Profile</router-link>
        <div v-if="currentUser.is_admin" class="admin-menu">
          <button class="link admin-toggle" type="button" @click="toggleAdmin">
            Admin ▾
          </button>
          <div v-if="adminOpen" class="admin-dropdown">
            <button type="button" @click="openUserManagement">User management</button>
            <button type="button" @click="openLogs">View logs</button>
            <button type="button" @click="openTrash">Trash</button>
            <button type="button" @click="runCleanStructure">Clean structure</button>
            <button type="button" @click="recheckSystemTools">Recheck system tools</button>
            <button type="button" @click="reenableAllTags">Re-enable all tags</button>
          </div>
        </div>
      </div>
      <div class="user" v-if="currentUser">
        <span>User: {{ currentUser.display_name }}</span>
        <button class="switch" type="button" @click="logout">Logout</button>
      </div>
    </nav>
    <div v-if="currentUser && currentUser.is_admin && toolWarnings.length" class="admin-warning">
      <div v-for="w in toolWarnings" :key="w">⚠ {{ w }}</div>
    </div>
    <router-view v-if="!forceChangeRequired" />
    <div v-if="forceChangeRequired" class="modal-backdrop">
      <div class="modal">
        <h3>Change password</h3>
        <p>You must change your password before continuing.</p>
        <label>
          Current password
          <input v-model="forcePassword.current" type="password" autocomplete="current-password" />
        </label>
        <label>
          New password
          <input v-model="forcePassword.next" type="password" autocomplete="new-password" />
        </label>
        <label>
          Confirm password
          <input v-model="forcePassword.confirm" type="password" autocomplete="new-password" />
        </label>
        <div class="modal-actions">
          <button class="inline" @click="submitForceChange" :disabled="loading">Save</button>
        </div>
        <p v-if="forceError" class="error">{{ forceError }}</p>
      </div>
    </div>
    <div v-if="usersOpen" class="modal-backdrop" @click.self="closeUserManagement">
      <div class="modal user-modal">
        <h3>User management</h3>
        <table class="tags-table">
          <thead>
            <tr>
              <th>UserID</th>
              <th>Username</th>
              <th>Display Name</th>
              <th>Active</th>
              <th>Admin</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <tr v-for="u in users" :key="u.id">
            <td>{{ u.id }}</td>
            <td>{{ u.username }}</td>
            <td>{{ u.display_name }}</td>
            <td>
              <input type="checkbox" v-model="u.is_active" @change="markUserDirty(u.id)" />
            </td>
            <td>
              <input type="checkbox" v-model="u.is_admin" @change="markUserDirty(u.id)" />
            </td>
            <td>
              <button class="inline" @click="editUser(u)">Edit</button>
              <button class="inline" @click="confirmDeleteUser(u)">Disable</button>
            </td>
          </tr>
          </tbody>
        </table>
        <div class="modal-actions">
          <button class="inline" @click="openNewUser">Add user</button>
          <button class="inline" :disabled="!usersDirty || loading" @click="saveUserFlags">
            Save changes
          </button>
          <button class="inline" @click="closeUserManagement">Close</button>
        </div>
        <p v-if="adminError" class="error">{{ adminError }}</p>
      </div>
    </div>

    <div v-if="editOpen" class="modal-backdrop" @click.self="closeEditUser">
      <div class="modal user-edit">
        <h3>{{ editMode === "new" ? "Create user" : "Edit user" }}</h3>
        <label>
          Username
          <input v-model.trim="editForm.username" type="text" />
        </label>
        <label>
          Display name
          <input v-model.trim="editForm.display_name" type="text" />
        </label>
        <label>
          Active
          <input v-model="editForm.is_active" type="checkbox" />
        </label>
        <label>
          Admin
          <input v-model="editForm.is_admin" type="checkbox" />
        </label>
        <label>
          Password
          <input v-model="editForm.password" type="password" autocomplete="new-password" />
        </label>
        <label>
          Confirm password
          <input v-model="editForm.confirm" type="password" autocomplete="new-password" />
        </label>
        <div class="modal-actions">
          <button class="inline" @click="saveUserEdit" :disabled="loading">Save</button>
          <button class="inline" @click="closeEditUser" :disabled="loading">Cancel</button>
        </div>
        <p v-if="editError" class="error">{{ editError }}</p>
      </div>
    </div>

    <div v-if="deleteOpen" class="modal-backdrop" @click.self="closeDeleteUser">
      <div class="modal">
        <h3>Disable user</h3>
        <p>Disable “{{ deleteTarget && deleteTarget.username }}”?</p>
        <div class="modal-actions">
          <button class="inline" @click="deleteUser" :disabled="loading">Disable</button>
          <button class="inline" @click="closeDeleteUser" :disabled="loading">Cancel</button>
        </div>
        <p v-if="adminError" class="error">{{ adminError }}</p>
      </div>
    </div>

    <div v-if="logsOpen" class="modal-backdrop" @click.self="closeLogs">
      <div class="modal logs-modal">
        <div class="modal-header">
          <h3>Audit logs</h3>
          <button class="inline close-btn" type="button" @click="closeLogs">Close</button>
        </div>
        <div class="logs-toolbar">
          <div class="summary" v-if="logsTotal !== null">
            Total: {{ logsTotal }} entries • Page {{ logsPage }} of {{ logsTotalPages }}
          </div>
          <div class="controls">
            <label>
              Page size
              <select v-model.number="logsPageSize" @change="applyLogsFilters">
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
              </select>
            </label>
          </div>
        </div>
        <div class="logs-filters">
          <label>
            Action
            <select v-if="logsMetaOk" v-model="logsFilters.action">
              <option value="">(Any)</option>
              <option v-for="action in logsMeta.actions" :key="action" :value="action">{{ action }}</option>
            </select>
            <input v-else v-model.trim="logsFilters.action" type="text" />
          </label>
          <label>
            Source
            <select v-if="logsMetaOk" v-model="logsFilters.source">
              <option value="">(Any)</option>
              <option v-for="source in logsMeta.sources" :key="source" :value="source">{{ source }}</option>
            </select>
            <input v-else v-model.trim="logsFilters.source" type="text" />
          </label>
          <label>
            Actor
            <select v-if="logsMetaOk" v-model.number="logsFilters.actor_user_id">
              <option :value="0">(Any)</option>
              <option v-for="actor in logsMeta.actors" :key="actor.id" :value="actor.id">
                {{ actor.label }}
              </option>
            </select>
            <input v-else v-model.trim="logsFilters.actor" type="text" />
          </label>
          <label>
            Target
            <select v-if="logsMetaOk" v-model.number="logsFilters.target_user_id">
              <option :value="0">(Any)</option>
              <option v-for="target in logsMeta.targets" :key="target.id" :value="target.id">
                {{ target.label }}
              </option>
            </select>
            <input v-else v-model.trim="logsFilters.target" type="text" />
          </label>
          <div class="filter-actions">
            <button class="inline" @click="applyLogsFilters" :disabled="loading">Apply</button>
            <button class="inline" @click="clearLogsFilters" :disabled="loading">Clear</button>
          </div>
        </div>
        <div class="pager" v-if="logsTotal !== null">
          <button :disabled="logsPage === 1 || loading" @click="prevLogs">Previous</button>
          <span>Page {{ logsPage }} of {{ logsTotalPages }}</span>
          <label class="jump">
            Jump
            <input v-model.number="logsJump" type="number" min="1" :max="logsTotalPages" />
          </label>
          <button class="inline" @click="jumpLogs" :disabled="loading">Go</button>
          <button :disabled="logsPage >= logsTotalPages || loading" @click="nextLogs">Next</button>
        </div>
        <p v-if="logsError" class="error">{{ logsError }}</p>
        <p v-if="logsTotal === 0" class="muted">No audit log entries yet.</p>
        <table class="tags-table logs-table" v-if="logs.length">
          <thead>
            <tr>
              <th>Time</th>
              <th>Action</th>
              <th>Source</th>
              <th>Actor</th>
              <th>Target</th>
              <th>IP</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in logs" :key="row.id">
              <td>{{ row.created_at }}</td>
              <td>{{ row.action }}</td>
              <td>{{ normalizeSource(row.source) }}</td>
              <td>{{ displayUser(row.actor_display_name, row.actor_username) }}</td>
              <td>{{ displayUser(row.target_display_name, row.target_username) }}</td>
              <td>{{ row.ip_address || "—" }}</td>
              <td>
                <span>{{ truncateDetails(row.details) }}</span>
                <button class="inline" v-if="hasDetails(row)" @click="openDetails(row)">View</button>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="pager" v-if="logsTotal !== null">
          <button :disabled="logsPage === 1 || loading" @click="prevLogs">Previous</button>
          <span>Page {{ logsPage }} of {{ logsTotalPages }}</span>
          <label class="jump">
            Jump
            <input v-model.number="logsJump" type="number" min="1" :max="logsTotalPages" />
          </label>
          <button class="inline" @click="jumpLogs" :disabled="loading">Go</button>
          <button :disabled="logsPage >= logsTotalPages || loading" @click="nextLogs">Next</button>
        </div>
      </div>
    </div>

    <div v-if="detailsOpen" class="modal-backdrop" @click.self="closeDetails">
      <div class="modal details-modal">
        <h3>Audit log details</h3>
        <div class="detail-block">
          <strong>Details</strong>
          <pre>{{ formatDetails(detailsRow && detailsRow.details) }}</pre>
        </div>
        <div class="detail-block">
          <strong>User agent</strong>
          <div>{{ (detailsRow && detailsRow.user_agent) || "—" }}</div>
        </div>
        <div class="modal-actions">
          <button class="inline" @click="closeDetails">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "App",
  data() {
    return {
      currentUser: null,
      prefs: null,
      adminOpen: false,
      usersOpen: false,
      users: [],
      usersDirty: false,
      dirtyUserIds: {},
      adminError: "",
      editOpen: false,
      editMode: "edit",
      editForm: {
        id: null,
        username: "",
        display_name: "",
        password: "",
        confirm: "",
        is_active: true,
        is_admin: false
      },
      editError: "",
      deleteOpen: false,
      deleteTarget: null,
      loading: false,
      forcePassword: {
        current: "",
        next: "",
        confirm: ""
      },
      forceError: "",
      logsOpen: false,
      logs: [],
      logsTotal: null,
      logsPage: 1,
      logsPageSize: 50,
      logsFilters: {
        action: "",
        source: "",
        actor_user_id: 0,
        target_user_id: 0,
        actor: "",
        target: ""
      },
      logsMeta: {
        actions: [],
        sources: [],
        actors: [],
        targets: []
      },
      logsMetaOk: false,
      logsJump: 1,
      logsError: "",
      detailsOpen: false,
      detailsRow: null,
      toolStatus: null
    };
  },
  mounted() {
    this.loadMe();
    window.addEventListener("wa-auth-changed", this.onAuthChanged);
    window.addEventListener("wa-prefs-refresh", this.loadPrefs);
  },
  beforeUnmount() {
    window.removeEventListener("wa-auth-changed", this.onAuthChanged);
    window.removeEventListener("wa-prefs-refresh", this.loadPrefs);
  },
  computed: {
    forceChangeRequired() {
      return !!(this.currentUser && this.currentUser.force_password_change);
    },
    logsTotalPages() {
      if (this.logsTotal === null || this.logsTotal === 0) {
        return 1;
      }
      return Math.max(1, Math.ceil(this.logsTotal / this.logsPageSize));
    },
    toolWarnings() {
      const tools = this.toolStatus && this.toolStatus.tools ? this.toolStatus.tools : {};
      const warnings = [];
      if (!tools.ffmpeg || tools.ffmpeg.available !== true) {
        warnings.push("Video thumbnails disabled: ffmpeg not found on server");
      }
      if (!tools.exiftool || tools.exiftool.available !== true) {
        warnings.push("Media tag editing disabled: exiftool not found on server");
      }
      return warnings;
    }
  },
  methods: {
    async loadMe() {
      try {
        const res = await fetch("/api/auth/me");
        if (!res.ok) {
          this.currentUser = null;
          window.__wa_current_user = null;
          return;
        }
        const data = await res.json();
        this.currentUser = data.user || null;
        window.__wa_current_user = this.currentUser;
        if (this.currentUser) {
          await this.loadPrefs();
          if (this.currentUser.is_admin) {
            await this.loadToolStatus();
          }
        }
      } catch (err) {
        // ignore
      }
    },
    async loadPrefs() {
      try {
        const res = await fetch("/api/prefs");
        if (!res.ok) {
          return;
        }
        const data = await res.json();
        this.prefs = data;
        window.__wa_prefs = data;
        window.dispatchEvent(new CustomEvent("wa-prefs-changed", { detail: data }));
      } catch (err) {
        // ignore
      }
    },
    onAuthChanged(event) {
      this.currentUser = event.detail || null;
      window.__wa_current_user = this.currentUser;
      if (this.currentUser) {
        this.loadPrefs();
        if (this.currentUser.is_admin) {
          this.loadToolStatus();
        } else {
          this.toolStatus = null;
        }
      } else {
        this.prefs = null;
        this.toolStatus = null;
        window.__wa_prefs = null;
      }
    },
    toggleAdmin() {
      this.adminOpen = !this.adminOpen;
    },
    async loadToolStatus() {
      try {
        const res = await fetch("/api/health");
        if (!res.ok) {
          return;
        }
        const data = await res.json();
        this.toolStatus = {
          tools: data.tools || {},
          checked_at: data.tools_checked_at || null
        };
      } catch (err) {
        // ignore health errors
      }
    },
    async recheckSystemTools() {
      this.adminOpen = false;
      this.loading = true;
      try {
        const res = await fetch("/api/admin/tools/recheck", { method: "POST" });
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          window.alert(data.error || "Tool recheck failed");
          return;
        }
        this.toolStatus = {
          tools: data.tools || {},
          checked_at: data.tools_checked_at || null
        };
      } catch (err) {
        window.alert("Tool recheck failed");
      } finally {
        this.loading = false;
      }
    },
    openLogs() {
      this.adminOpen = false;
      this.logsPage = 1;
      this.logsJump = 1;
      this.fetchLogsMeta();
      this.fetchLogs();
      this.logsOpen = true;
    },
    openTrash() {
      this.adminOpen = false;
      this.$router.push("/trash");
    },
    async reenableAllTags() {
      this.adminOpen = false;
      if (!window.confirm("Re-enable all tags globally and for all users?")) {
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/admin/tags/reenable-all", { method: "POST" });
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          window.alert(data.error || "Failed to re-enable tags");
          return;
        }
        window.alert("All tags are re-enabled.");
      } catch (err) {
        window.alert("Failed to re-enable tags");
      } finally {
        this.loading = false;
      }
    },
    async runCleanStructure() {
      this.adminOpen = false;
      if (!window.confirm("Remove empty folders across photos/thumbs/trash roots?")) {
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/admin/maintenance/clean-structure", { method: "POST" });
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          window.alert(data.error || "Clean structure failed");
          return;
        }
        const report = data.report || {};
        const parts = ["photos", "thumbs", "trash", "trash_thumbs"].map((key) => {
          const row = report[key] || {};
          const deleted = Number(row.deleted || 0);
          const blocked = Number(row.skipped_due_to_trash_blocker || 0);
          return `${key}: deleted ${deleted}, blocked ${blocked}`;
        });
        window.alert(`Clean structure done\n${parts.join("\n")}`);
      } catch (err) {
        window.alert("Clean structure failed");
      } finally {
        this.loading = false;
      }
    },
    closeLogs() {
      this.logsOpen = false;
      this.logs = [];
      this.logsTotal = null;
      this.logsError = "";
      this.logsMetaOk = false;
      this.detailsOpen = false;
      this.detailsRow = null;
    },
    async fetchLogs() {
      this.loading = true;
      this.logsError = "";
      try {
        const qs = new URLSearchParams();
        qs.set("page", String(this.logsPage));
        qs.set("page_size", String(this.logsPageSize));
        if (this.logsFilters.action) {
          qs.set("action", this.logsFilters.action);
        }
        if (this.logsFilters.source) {
          qs.set("source", this.logsFilters.source);
        }
        if (this.logsMetaOk) {
          if (this.logsFilters.actor_user_id) {
            qs.set("actor_user_id", String(this.logsFilters.actor_user_id));
          }
          if (this.logsFilters.target_user_id) {
            qs.set("target_user_id", String(this.logsFilters.target_user_id));
          }
        } else {
          if (this.logsFilters.actor) {
            qs.set("actor", this.logsFilters.actor);
          }
          if (this.logsFilters.target) {
            qs.set("target", this.logsFilters.target);
          }
        }
        const res = await fetch(`/api/admin/audit-logs?${qs.toString()}`);
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.logsError = data.error || "Failed to load logs";
          return;
        }
        this.logs = data.rows || [];
        this.logsTotal = typeof data.total === "number" ? data.total : 0;
        if (typeof data.page === "number") {
          this.logsPage = data.page;
        }
        if (typeof data.page_size === "number") {
          this.logsPageSize = data.page_size;
        }
      } catch (err) {
        this.logsError = "Failed to load logs";
      } finally {
        this.loading = false;
      }
    },
    async fetchLogsMeta() {
      if (this.logsMetaOk && this.logsMeta.actions.length) {
        return;
      }
      if (window.__wa_logs_meta && window.__wa_logs_meta_ok) {
        const cached = window.__wa_logs_meta;
        const hasActors = Array.isArray(cached.actors);
        const hasTargets = Array.isArray(cached.targets);
        if (hasActors && hasTargets) {
          this.logsMeta = cached;
          this.logsMetaOk = true;
          return;
        }
      }
      this.logsMetaOk = false;
      try {
        const res = await fetch("/api/admin/audit-logs/meta");
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          return;
        }
        this.logsMeta = {
          actions: Array.isArray(data.actions) ? data.actions : [],
          sources: Array.isArray(data.sources) ? data.sources : [],
          actors: Array.isArray(data.actors) ? data.actors : [],
          targets: Array.isArray(data.targets) ? data.targets : []
        };
        this.logsMetaOk = true;
        window.__wa_logs_meta = this.logsMeta;
        window.__wa_logs_meta_ok = true;
      } catch (err) {
        this.logsMetaOk = false;
      }
    },
    applyLogsFilters() {
      this.logsPage = 1;
      this.logsJump = 1;
      this.fetchLogs();
    },
    clearLogsFilters() {
      this.logsFilters = {
        action: "",
        source: "",
        actor_user_id: 0,
        target_user_id: 0,
        actor: "",
        target: ""
      };
      this.applyLogsFilters();
    },
    nextLogs() {
      if (this.logsPage < this.logsTotalPages) {
        this.logsPage += 1;
        this.logsJump = this.logsPage;
        this.fetchLogs();
      }
    },
    prevLogs() {
      if (this.logsPage > 1) {
        this.logsPage -= 1;
        this.logsJump = this.logsPage;
        this.fetchLogs();
      }
    },
    jumpLogs() {
      const target = Math.max(1, Math.min(this.logsTotalPages, Number(this.logsJump) || 1));
      if (target === this.logsPage) {
        return;
      }
      this.logsPage = target;
      this.fetchLogs();
    },
    displayUser(displayName, username) {
      return displayName || username || "—";
    },
    normalizeSource(source) {
      if (!source) {
        return "—";
      }
      if (source === "self") {
        return "ui";
      }
      return source;
    },
    truncateDetails(details) {
      if (!details) {
        return "—";
      }
      const text = typeof details === "string" ? details : JSON.stringify(details);
      if (text.length <= 120) {
        return text;
      }
      return text.slice(0, 120) + "...";
    },
    formatDetails(details) {
      if (!details) {
        return "—";
      }
      if (typeof details === "string") {
        return details;
      }
      try {
        return JSON.stringify(details, null, 2);
      } catch (err) {
        return String(details);
      }
    },
    hasDetails(row) {
      if (!row) {
        return false;
      }
      return !!(row.details || row.user_agent);
    },
    openDetails(row) {
      this.detailsRow = row;
      this.detailsOpen = true;
    },
    closeDetails() {
      this.detailsOpen = false;
      this.detailsRow = null;
    },
    async openUserManagement() {
      this.adminOpen = false;
      await this.fetchUsers();
      this.usersOpen = true;
    },
    closeUserManagement() {
      this.usersOpen = false;
      this.adminError = "";
      this.usersDirty = false;
      this.dirtyUserIds = {};
    },
    async fetchUsers() {
      this.loading = true;
      this.adminError = "";
      try {
        const res = await fetch("/api/users");
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.adminError = data.error || "Failed to load users";
          return;
        }
        this.users = data.map((row) => ({
          ...row,
          is_active: !!row.is_active,
          is_admin: !!row.is_admin
        }));
        this.usersDirty = false;
        this.dirtyUserIds = {};
      } catch (err) {
        this.adminError = "Failed to load users";
      } finally {
        this.loading = false;
      }
    },
    markUserDirty(id) {
      this.dirtyUserIds[id] = true;
      this.usersDirty = true;
    },
    async saveUserFlags() {
      const ids = Object.keys(this.dirtyUserIds);
      if (ids.length === 0) {
        return;
      }
      this.loading = true;
      this.adminError = "";
      try {
        for (const id of ids) {
          const user = this.users.find((row) => String(row.id) === String(id));
          if (!user) {
            continue;
          }
          const res = await fetch(`/api/users/${user.id}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              is_active: user.is_active ? 1 : 0,
              is_admin: user.is_admin ? 1 : 0
            })
          });
          if (res.status === 401 || res.status === 403) {
            this.onAuthChanged({ detail: null });
            this.$router.push("/login");
            return;
          }
          const data = await res.json();
          if (!res.ok) {
            this.adminError = data.error || "Failed to save changes";
            return;
          }
        }
        this.usersDirty = false;
        this.dirtyUserIds = {};
      } catch (err) {
        this.adminError = "Failed to save changes";
      } finally {
        this.loading = false;
      }
    },
    openNewUser() {
      this.editMode = "new";
      this.editForm = {
        id: null,
        username: "",
        display_name: "",
        password: "",
        confirm: "",
        is_active: true,
        is_admin: false
      };
      this.editError = "";
      this.editOpen = true;
    },
    editUser(user) {
      this.editMode = "edit";
      this.editForm = {
        id: user.id,
        username: user.username,
        display_name: user.display_name,
        password: "",
        confirm: "",
        is_active: !!user.is_active,
        is_admin: !!user.is_admin
      };
      this.editError = "";
      this.editOpen = true;
    },
    closeEditUser() {
      this.editOpen = false;
      this.editError = "";
    },
    async saveUserEdit() {
      const username = this.editForm.username.trim();
      const displayName = this.editForm.display_name.trim();
      if (!username) {
        this.editError = "Username is required";
        return;
      }
      if (!displayName) {
        this.editError = "Display name is required";
        return;
      }
      if (this.editForm.password && this.editForm.password.length < 8) {
        this.editError = "Password must be at least 8 characters";
        return;
      }
      if (this.editMode === "new" && !this.editForm.password) {
        this.editError = "Password is required";
        return;
      }
      if (this.editForm.password && this.editForm.password !== this.editForm.confirm) {
        this.editError = "Passwords do not match";
        return;
      }
      this.loading = true;
      this.editError = "";
      try {
      if (this.editMode === "new") {
          const res = await fetch("/api/users", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              username,
              display_name: displayName,
              password: this.editForm.password,
              is_active: this.editForm.is_active ? 1 : 0,
              is_admin: this.editForm.is_admin ? 1 : 0
            })
          });
          if (res.status === 401 || res.status === 403) {
            this.onAuthChanged({ detail: null });
            this.$router.push("/login");
            return;
          }
          const data = await res.json();
          if (!res.ok) {
            this.editError = data.error || "Failed to create user";
            return;
          }
        } else {
          const payload = {
            username,
            display_name: displayName,
            is_active: this.editForm.is_active ? 1 : 0,
            is_admin: this.editForm.is_admin ? 1 : 0
          };
          if (this.editForm.password) {
            payload.password = this.editForm.password;
          }
          const res = await fetch(`/api/users/${this.editForm.id}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload)
          });
          if (res.status === 401 || res.status === 403) {
            this.onAuthChanged({ detail: null });
            this.$router.push("/login");
            return;
          }
          const data = await res.json();
          if (!res.ok) {
            this.editError = data.error || "Failed to update user";
            return;
          }
        }
        this.editOpen = false;
        await this.fetchUsers();
      } catch (err) {
        this.editError = "Failed to save user";
      } finally {
        this.loading = false;
      }
    },
    confirmDeleteUser(user) {
      this.deleteTarget = user;
      this.deleteOpen = true;
      this.adminError = "";
    },
    closeDeleteUser() {
      this.deleteOpen = false;
      this.deleteTarget = null;
    },
    async deleteUser() {
      if (!this.deleteTarget) {
        return;
      }
      this.loading = true;
      this.adminError = "";
      try {
        const res = await fetch(`/api/users/${this.deleteTarget.id}`, {
          method: "DELETE"
        });
        if (res.status === 401 || res.status === 403) {
          this.onAuthChanged({ detail: null });
          this.$router.push("/login");
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.adminError = data.error || "Failed to disable user";
          return;
        }
        this.deleteOpen = false;
        await this.fetchUsers();
      } catch (err) {
        this.adminError = "Failed to disable user";
      } finally {
        this.loading = false;
      }
    },
    async logout() {
      try {
        await fetch("/api/auth/logout", { method: "POST" });
      } catch (err) {
        // ignore
      } finally {
        this.currentUser = null;
        window.__wa_current_user = null;
        window.__wa_logs_meta = null;
        window.__wa_logs_meta_ok = false;
        window.__wa_prefs = null;
        window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: null }));
        if (this.$route.path !== "/login") {
          this.$router.push("/login");
        }
      }
    },
    validateStrongPassword(password) {
      if (!password || password.length < 12) {
        return "Password must be at least 12 characters";
      }
      if (!/[a-z]/.test(password)) {
        return "Password must include a lowercase letter";
      }
      if (!/[A-Z]/.test(password)) {
        return "Password must include an uppercase letter";
      }
      if (!/[0-9]/.test(password)) {
        return "Password must include a number";
      }
      if (!/[^A-Za-z0-9]/.test(password)) {
        return "Password must include a special character";
      }
      return "";
    },
    async submitForceChange() {
      this.forceError = "";
      if (!this.forcePassword.current) {
        this.forceError = "Current password is required";
        return;
      }
      const error = this.validateStrongPassword(this.forcePassword.next);
      if (error) {
        this.forceError = error;
        return;
      }
      if (this.forcePassword.next !== this.forcePassword.confirm) {
        this.forceError = "Passwords do not match";
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/users/me/password", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            current_password: this.forcePassword.current,
            new_password: this.forcePassword.next,
            confirm_password: this.forcePassword.confirm
          })
        });
        const data = await res.json();
        if (!res.ok) {
          this.forceError = data.error || "Password change failed";
          return;
        }
        await fetch("/api/auth/logout", { method: "POST" });
        this.forcePassword.current = "";
        this.forcePassword.next = "";
        this.forcePassword.confirm = "";
        this.forceError = "";
        this.currentUser = null;
        window.__wa_current_user = null;
        this.$router.push("/login");
      } catch (err) {
        this.forceError = "Password change failed";
      } finally {
        this.loading = false;
      }
    }
  }
};
</script>

<style>
:root {
  --bg: #f3efe7;
  --ink: #1b1b1b;
  --accent: #0b4f6c;
  --panel: #ffffff;
  --muted: #6b6b6b;
}

body {
  margin: 0;
  font-family: "Georgia", "Times New Roman", serif;
  color: var(--ink);
  background: radial-gradient(circle at 20% 10%, #fff8e8, var(--bg));
}

#app {
  width: 100%;
  min-height: 100vh;
  margin: 0;
  padding: 16px 12px 24px;
  box-sizing: border-box;
}

.top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}

.brand {
  font-weight: bold;
  letter-spacing: 0.5px;
}

.version {
  font-size: 12px;
  color: var(--muted);
  margin-left: 8px;
}

.links {
  display: flex;
  gap: 12px;
}

.user {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  color: var(--muted);
}

.switch {
  background: transparent;
  border: 1px solid #d6c9b5;
  color: var(--ink);
  padding: 6px 10px;
  border-radius: 8px;
  cursor: pointer;
}

.link {
  color: var(--accent);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  padding-bottom: 2px;
}

.admin-toggle {
  font: inherit;
  background: transparent;
  border: 0;
  color: var(--accent);
  padding: 0;
  cursor: pointer;
}

.link.active {
  border-bottom-color: var(--accent);
}

.hero h1 {
  font-size: 48px;
  margin: 0 0 8px;
}

.hero p {
  margin: 0 0 24px;
  color: var(--muted);
}

.panel {
  background: var(--panel);
  border: 1px solid #e3dccf;
  border-radius: 12px;
  padding: 16px;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.06);
}

.login-panel {
  max-width: 420px;
}

.logs-modal {
  width: 96vw;
  max-width: 1800px;
  max-height: 85vh;
  overflow: auto;
}

.logs-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.logs-filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.logs-filters label {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.filter-actions {
  display: flex;
  gap: 8px;
  align-items: flex-end;
}

.logs-table td,
.logs-table th {
  vertical-align: top;
}

.logs-table td:last-child {
  min-width: 220px;
}


.pager {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 8px 0;
  flex-wrap: wrap;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.close-btn {
  margin-left: auto;
}

.pager .jump {
  display: flex;
  align-items: center;
  gap: 6px;
}

.details-modal pre {
  background: #f7f4ee;
  padding: 12px;
  border-radius: 8px;
  max-height: 320px;
  overflow: auto;
}

.detail-block {
  margin-bottom: 12px;
}

.picker h2 {
  margin: 0 0 12px;
}

.picker-list {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}

.picker-card {
  background: #ffffff;
  border: 1px solid #e3dccf;
  border-radius: 12px;
  padding: 14px 18px;
  cursor: pointer;
  font-size: 14px;
  color: var(--ink);
}

.picker-card:hover {
  border-color: #b9a78f;
}

.picker .empty {
  color: var(--muted);
  font-size: 14px;
}

.overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1200;
}

.overlay .picker {
  width: min(600px, 90vw);
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1300;
}

.modal {
  background: #ffffff;
  border: 1px solid #e3dccf;
  border-radius: 12px;
  padding: 18px;
  width: min(520px, 92vw);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.18);
}

.modal.logs-modal {
  width: 96vw;
  max-width: 1800px;
}

.modal h3 {
  margin: 0 0 12px;
}

.modal-actions {
  display: flex;
  gap: 10px;
  margin-top: 12px;
}

.row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

label {
  display: flex;
  flex-direction: column;
  font-size: 14px;
  gap: 6px;
  min-width: 180px;
}

label select {
  min-width: 140px;
}

.tags {
  min-width: 280px;
}

.tag-rows {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.tag-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.tag-row input {
  flex: 1;
}

.tag-remove {
  border: 1px solid #d6c9b5;
  background: #f9f4ea;
  color: var(--ink);
  padding: 4px 8px;
  border-radius: 8px;
  cursor: pointer;
}

.tag-add {
  margin-top: 6px;
  align-self: flex-start;
  background: transparent;
  color: var(--accent);
  border: 1px dashed #b9a78f;
  padding: 6px 10px;
  border-radius: 8px;
  cursor: pointer;
}

.suggestions {
  margin-top: 8px;
  border: 1px solid #e3dccf;
  border-radius: 8px;
  background: #fffdf7;
  max-height: 180px;
  overflow: auto;
}

.suggestion {
  width: 100%;
  display: flex;
  justify-content: space-between;
  gap: 8px;
  background: transparent;
  border: none;
  padding: 6px 10px;
  cursor: pointer;
  text-align: left;
  color: #000000;
}

.suggestion:hover {
  background: #f6efe2;
}

.suggestion .name {
  font-family: "Courier New", monospace;
}

.suggestion .count {
  color: var(--muted);
  font-size: 12px;
}

input,
select {
  padding: 8px 10px;
  border: 1px solid #d6c9b5;
  border-radius: 8px;
  font-size: 14px;
}

button {
  background: var(--accent);
  color: white;
  border: none;
  padding: 10px 14px;
  border-radius: 8px;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: default;
}

.actions {
  align-items: center;
}

.checkbox {
  flex-direction: row;
  align-items: center;
  gap: 8px;
  font-size: 14px;
}

.error {
  color: #9b1c1c;
}

.results {
  margin-top: 24px;
}

.results-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 8px;
}

.results-table th,
.results-table td {
  text-align: left;
  padding: 8px;
  border-bottom: 1px dashed #d9cbb6;
  font-size: 14px;
}

.results-table .num {
  width: 40px;
  color: var(--muted);
}

.results-table .fav {
  width: 36px;
}

.results-table .thumb {
  width: 72px;
}

.results-table .thumb img {
  width: 64px;
  height: 64px;
  object-fit: cover;
  border-radius: 6px;
  border: 1px solid #e3dccf;
  display: block;
  background: linear-gradient(90deg, #f2eadc 25%, #f7f1e6 50%, #f2eadc 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease infinite;
}

.results-table .thumb img.loaded {
  animation: none;
  background: none;
}

.thumb-placeholder {
  display: inline-block;
  width: 64px;
  height: 64px;
  line-height: 64px;
  text-align: center;
  color: var(--muted);
  border: 1px dashed #d9cbb6;
  border-radius: 6px;
  background: #fbf6ec;
}

.results-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
  margin-top: 12px;
}

.grid-item {
  border: 1px solid #e3dccf;
  border-radius: 10px;
  padding: 10px;
  background: #ffffff;
  display: grid;
  gap: 8px;
  outline: none;
}

.grid-item:focus {
  box-shadow: 0 0 0 2px rgba(11, 79, 108, 0.35);
}

.grid-thumb {
  position: relative;
}

.grid-thumb img {
  width: 100%;
  height: 160px;
  object-fit: cover;
  border-radius: 8px;
  border: 1px solid #e3dccf;
  display: block;
  background: linear-gradient(90deg, #f2eadc 25%, #f7f1e6 50%, #f2eadc 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease infinite;
}

.grid-thumb img.loaded {
  animation: none;
  background: none;
}

.view-toggle {
  display: inline-flex;
  gap: 4px;
  margin: 8px 0 12px;
}

.view-toggle button {
  border: 1px solid #d6c9b5;
  background: #ffffff;
  color: #000000;
  padding: 6px 10px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 12px;
}

.view-toggle button.active {
  background: var(--accent);
  color: #ffffff;
  border-color: var(--accent);
}

.grid-check {
  position: absolute;
  top: 6px;
  left: 6px;
  background: rgba(255, 255, 255, 0.9);
  border-radius: 6px;
  padding: 2px 4px;
  z-index: 3;
}

.grid-check.right {
  left: auto;
  right: 6px;
}

.grid-num {
  position: absolute;
  top: 6px;
  right: 38px;
  font-size: 12px;
  color: #000000;
  background: rgba(255, 255, 255, 0.98);
  padding: 2px 6px;
  border-radius: 6px;
  z-index: 5;
}

.grid-meta {
  display: grid;
  gap: 4px;
}

.grid-name {
  color: var(--ink);
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  background: transparent;
  border: none;
  padding: 0;
  text-align: left;
  cursor: pointer;
}

.grid-name:hover {
  text-decoration: underline;
}

.grid-date {
  font-size: 12px;
  color: var(--muted);
}

.grid-actions {
  display: flex;
  justify-content: flex-end;
}

@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

.results-table .path a {
  color: var(--ink);
  text-decoration: none;
}

.results-table .path a:hover {
  text-decoration: underline;
}

.link {
  background: transparent;
  border: none;
  padding: 0;
  cursor: pointer;
  color: var(--ink);
}

.link.text {
  text-align: left;
}

.link:hover {
  text-decoration: underline;
}

.copy {
  margin-left: 8px;
  border: 1px solid #d6c9b5;
  background: #f9f4ea;
  color: #000000;
  padding: 2px 6px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
}

.star {
  margin-left: 0;
  border: 1px solid #e3dccf;
  background: #fff7df;
  color: #9c6b00;
  padding: 2px 6px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
}

.grid-star {
  position: absolute;
  top: 6px;
  left: 6px;
  border: 1px solid #e3dccf;
  background: rgba(255, 247, 223, 0.98);
  color: #7a5200;
  padding: 2px 6px;
  border-radius: 6px;
  font-size: 14px;
  cursor: pointer;
  z-index: 4;
}

.download {
  background: var(--accent);
  color: white;
  border: none;
  padding: 8px 12px;
  border-radius: 8px;
  cursor: pointer;
}

.note {
  color: var(--muted);
  font-size: 12px;
}

.clear {
  border: 1px solid #d6c9b5;
  background: #ffffff;
  color: #000000;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 12px;
  cursor: pointer;
}

.viewer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  z-index: 1000;
}

.viewer-panel {
  width: min(1200px, 96vw);
  height: min(96vh, 980px);
  background: #111;
  color: #fff;
  display: flex;
  flex-direction: column;
  border-radius: 12px;
  overflow: hidden;
}

.viewer-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  background: #1b1b1b;
}

.viewer-title {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.viewer-count {
  font-size: 12px;
  color: #bbb;
}

.viewer-btn {
  background: #2a2a2a;
  color: #fff;
  border: 1px solid #3a3a3a;
  padding: 6px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
}

.viewer-body {
  flex: 1;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 12px;
}

.viewer-media {
  flex: 1;
  min-width: 0;
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.viewer-img {
  display: block;
  max-width: 90vw;
  max-height: calc(90vh - 72px);
  width: auto;
  height: auto;
  object-fit: contain;
}

.viewer-video {
  display: block;
  max-width: 95vw;
  max-height: calc(90vh - 72px);
  width: auto;
  height: auto;
  background: #000;
}

.viewer-placeholder {
  color: #bbb;
}

.viewer-tags {
  padding: 8px 14px 12px;
  font-size: 12px;
  color: #d8d8d8;
  background: #161616;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.nav-btn {
  width: 44px;
  height: 44px;
  border-radius: 999px;
  background: #2a2a2a;
  border: 1px solid #3a3a3a;
  color: #fff;
  font-size: 24px;
  cursor: pointer;
}

.nav-btn:disabled {
  opacity: 0.4;
  cursor: default;
}

.pager {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 8px 0 12px;
}

.pager span {
  color: var(--muted);
  font-size: 14px;
}

.pager input {
  width: 90px;
}

.toast {
  position: fixed;
  right: 24px;
  bottom: 24px;
  background: #1e1e1e;
  color: #ffffff;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 14px;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.results ul {
  list-style: none;
  padding: 0;
  margin: 12px 0 0;
}

.results li {
  padding: 10px 0;
  border-bottom: 1px dashed #d9cbb6;
  display: flex;
  gap: 12px;
  align-items: center;
}

.pill {
  background: #efe2c9;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  color: #5a4c39;
}

.ts {
  color: var(--muted);
  font-size: 12px;
}

.debug {
  background: #1e1e1e;
  color: #f1f1f1;
  padding: 12px;
  border-radius: 8px;
  overflow: auto;
}

.page {
  padding-bottom: 20px;
}

.tags-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 8px;
}

.tags-table th,
.tags-table td {
  text-align: left;
  padding: 8px;
  border-bottom: 1px solid #e3dccf;
  font-size: 14px;
}

.tags-table th {
  background: #fbf6ec;
}

.tags-table .tag {
  font-family: "Courier New", monospace;
}

.tags-table .status {
  color: var(--muted);
  font-size: 12px;
}

.inline {
  align-self: flex-end;
  margin-top: 18px;
}

.variants .dots {
  font-size: 14px;
  letter-spacing: 2px;
}

.loaded-indicator {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
  font-size: 14px;
  color: var(--muted);
}

.admin-menu {
  position: relative;
}

.admin-toggle {
  background: transparent;
  border: none;
  padding: 0;
  cursor: pointer;
}

.admin-dropdown {
  position: absolute;
  top: 26px;
  left: 0;
  background: #ffffff;
  border: 1px solid #e3dccf;
  border-radius: 8px;
  padding: 6px;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 150px;
  z-index: 1300;
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
}

.admin-dropdown button {
  background: transparent;
  border: none;
  text-align: left;
  padding: 6px 8px;
  cursor: pointer;
  color: var(--ink);
}

.user-modal {
  width: min(900px, 95vw);
  max-height: 80vh;
  overflow: auto;
}

.user-edit {
  width: min(500px, 90vw);
}
</style>


<style>
.admin-warning {
  margin: -10px 0 14px;
  border: 1px solid #e2b8ae;
  background: #fff4f1;
  color: #7a1f10;
  border-radius: 10px;
  padding: 8px 12px;
  font-size: 13px;
  display: grid;
  gap: 4px;
}
</style>
