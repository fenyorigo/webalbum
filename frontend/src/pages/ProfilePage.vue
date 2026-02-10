<template>
  <div class="page">
    <header class="hero">
      <h1>My Profile</h1>
      <p>Adjust your default preferences.</p>
    </header>

    <section class="panel">
      <div class="row">
        <label>
          Default view
          <select v-model="prefs.default_view">
            <option value="grid">Grid</option>
            <option value="list">List</option>
          </select>
        </label>
        <label>
          Page size
          <input v-model.number="prefs.page_size" type="number" min="10" max="200" />
        </label>
        <label>
          Thumb size
          <input v-model.number="prefs.thumb_size" type="number" min="100" max="400" />
        </label>
        <label>
          Sort mode
          <select v-model="prefs.sort_mode">
            <option value="name_az">Name A-Z</option>
            <option value="name_za">Name Z-A</option>
            <option value="date_new_old">Date New-Old</option>
            <option value="date_old_new">Date Old-New</option>
          </select>
        </label>
      </div>
      <div class="row actions">
        <button @click="save" :disabled="loading">Save</button>
      </div>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="saved" class="pill">Saved</p>
    </section>

    <section class="panel">
      <h3>Change password</h3>
      <label>
        Current password
        <input v-model="password.current" type="password" autocomplete="current-password" />
      </label>
      <label>
        New password
        <input v-model="password.next" type="password" autocomplete="new-password" />
      </label>
      <label>
        Confirm password
        <input v-model="password.confirm" type="password" autocomplete="new-password" />
      </label>
      <div class="row actions">
        <button @click="changePassword" :disabled="loading">Update password</button>
      </div>
      <p v-if="passwordError" class="error">{{ passwordError }}</p>
      <p v-if="passwordSaved" class="pill">Password updated</p>
    </section>
  </div>
</template>

<script>
export default {
  name: "ProfilePage",
  data() {
    return {
      prefs: {
        default_view: "grid",
        page_size: 50,
        thumb_size: 180,
        sort_mode: "name_az"
      },
      password: {
        current: "",
        next: "",
        confirm: ""
      },
      passwordError: "",
      passwordSaved: false,
      loading: false,
      error: "",
      saved: false
    };
  },
  mounted() {
    this.loadPrefs();
  },
  methods: {
    async loadPrefs() {
      this.loading = true;
      this.error = "";
      try {
        const res = await fetch("/api/prefs");
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load preferences";
          return;
        }
        this.prefs = data;
      } catch (err) {
        this.error = "Failed to load preferences";
      } finally {
        this.loading = false;
      }
    },
    async save() {
      this.loading = true;
      this.error = "";
      this.saved = false;
      try {
        const res = await fetch("/api/prefs", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(this.prefs)
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to save preferences";
          return;
        }
        this.prefs = data;
        this.saved = true;
        window.dispatchEvent(new CustomEvent("wa-prefs-changed", { detail: data }));
      } catch (err) {
        this.error = "Failed to save preferences";
      } finally {
        this.loading = false;
        setTimeout(() => {
          this.saved = false;
        }, 1500);
      }
    },
    async changePassword() {
      this.passwordError = "";
      this.passwordSaved = false;
      const error = this.validateStrongPassword(this.password.next);
      if (error) {
        this.passwordError = error;
        return;
      }
      if (this.password.next !== this.password.confirm) {
        this.passwordError = "Passwords do not match";
        return;
      }
      if (!this.password.current) {
        this.passwordError = "Current password is required";
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/users/me/password", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            current_password: this.password.current,
            new_password: this.password.next,
            confirm_password: this.password.confirm
          })
        });
        if (this.handleAuthError(res)) {
          return;
        }
        const data = await res.json();
        if (!res.ok) {
          this.passwordError = data.error || "Password change failed";
          return;
        }
        this.passwordSaved = true;
        this.password.current = "";
        this.password.next = "";
        this.password.confirm = "";
        setTimeout(() => {
          this.passwordSaved = false;
        }, 1500);
      } catch (err) {
        this.passwordError = "Password change failed";
      } finally {
        this.loading = false;
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
