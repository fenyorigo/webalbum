<template>
  <div class="page">
    <header class="hero">
      <h1 v-if="!setupRequired">Login</h1>
      <h1 v-else>Create admin user</h1>
      <p v-if="!setupRequired">Sign in to access Webalbum.</p>
      <p v-else>Set up the initial admin account.</p>
    </header>

    <section class="panel login-panel" v-if="!setupRequired">
      <label>
        Username
        <input v-model.trim="username" type="text" autocomplete="username" />
      </label>
      <label>
        Password
        <input v-model="password" type="password" autocomplete="current-password" />
      </label>
      <button @click="submit" :disabled="loading">Login</button>
      <p v-if="error" class="error">{{ error }}</p>
    </section>

    <div v-else class="modal-backdrop">
      <div class="modal">
        <h3>Create admin user</h3>
        <label>
          Admin username
          <input v-model.trim="setup.username" type="text" autocomplete="username" />
        </label>
        <label>
          Password
          <input v-model="setup.password" type="password" autocomplete="new-password" />
        </label>
        <label>
          Confirm password
          <input v-model="setup.confirm" type="password" autocomplete="new-password" />
        </label>
        <div class="modal-actions">
          <button class="inline" @click="submitSetup" :disabled="loading">Create admin</button>
        </div>
        <p v-if="setupError" class="error">{{ setupError }}</p>
      </div>
    </div>

  </div>
</template>

<script>
export default {
  name: "LoginPage",
  data() {
    return {
      username: "",
      password: "",
      loading: false,
      error: "",
      setupRequired: false,
      setup: {
        username: "",
        password: "",
        confirm: ""
      },
      setupError: "",
      forceChange: false,
      force: {
        newPassword: "",
        confirm: ""
      },
      forceError: "",
      lastLoginPassword: ""
    };
  },
  mounted() {
    this.checkSetup();
  },
  methods: {
    async checkSetup() {
      try {
        const res = await fetch("/api/setup/status");
        if (!res.ok) {
          return;
        }
        const data = await res.json();
        this.setupRequired = !!data.setup_required;
      } catch (err) {
        // ignore
      }
    },
    async submit() {
      this.error = "";
      if (!this.username || !this.password) {
        this.error = "Username and password are required";
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/auth/login", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ username: this.username, password: this.password })
        });
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Login failed";
          return;
        }
        const user = data.user || null;
        if (user) {
          window.dispatchEvent(new CustomEvent("wa-auth-changed", { detail: user }));
        }
        this.$router.push("/");
      } catch (err) {
        this.error = "Login failed";
      } finally {
        this.loading = false;
      }
    },
    async submitSetup() {
      this.setupError = "";
      if (!this.setup.username) {
        this.setupError = "Username is required";
        return;
      }
      const error = this.validateStrongPassword(this.setup.password);
      if (error) {
        this.setupError = error;
        return;
      }
      if (this.setup.password !== this.setup.confirm) {
        this.setupError = "Passwords do not match";
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/setup", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ username: this.setup.username, password: this.setup.password })
        });
        const data = await res.json();
        if (!res.ok) {
          this.setupError = data.error || "Setup failed";
          return;
        }
        this.setupRequired = false;
        this.$router.push("/login");
      } catch (err) {
        this.setupError = "Setup failed";
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
    }
  }
};
</script>
