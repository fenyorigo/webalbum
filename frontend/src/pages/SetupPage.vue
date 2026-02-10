<template>
  <div class="page">
    <header class="hero">
      <h1>Initial setup</h1>
      <p>Create the first admin account.</p>
    </header>

    <section class="panel login-panel">
      <label>
        Admin username
        <input v-model.trim="username" type="text" autocomplete="username" />
      </label>
      <label>
        Password
        <input v-model="password" type="password" autocomplete="new-password" />
      </label>
      <label>
        Re-enter password
        <input v-model="confirm" type="password" autocomplete="new-password" />
      </label>
      <button @click="submit" :disabled="loading">Create admin</button>
      <p v-if="error" class="error">{{ error }}</p>
    </section>
  </div>
</template>

<script>
export default {
  name: "SetupPage",
  data() {
    return {
      username: "",
      password: "",
      confirm: "",
      loading: false,
      error: ""
    };
  },
  methods: {
    async submit() {
      this.error = "";
      if (!this.username) {
        this.error = "Username is required";
        return;
      }
      const error = this.validateStrongPassword(this.password);
      if (error) {
        this.error = error;
        return;
      }
      if (this.password !== this.confirm) {
        this.error = "Passwords do not match";
        return;
      }
      this.loading = true;
      try {
        const res = await fetch("/api/setup", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ username: this.username, password: this.password })
        });
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Setup failed";
          return;
        }
        this.$router.push("/login");
      } catch (err) {
        this.error = "Setup failed";
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
