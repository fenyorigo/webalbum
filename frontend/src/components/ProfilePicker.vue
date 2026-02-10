<template>
  <section class="panel picker">
    <h2>Select a profile</h2>
    <div class="picker-list" v-if="users.length">
      <button
        v-for="user in users"
        :key="user.id"
        class="picker-card"
        type="button"
        @click="selectUser(user.id)"
      >
        {{ user.display_name }}
      </button>
    </div>
    <p v-else class="empty">No users found. Seed wa_users first.</p>
    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>

<script>
export default {
  name: "ProfilePicker",
  emits: ["selected"],
  data() {
    return {
      users: [],
      error: ""
    };
  },
  mounted() {
    this.loadUsers();
  },
  methods: {
    async loadUsers() {
      try {
        const res = await fetch("/api/users");
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to load users";
          return;
        }
        this.users = data;
      } catch (err) {
        this.error = err.message || String(err);
      }
    },
    async selectUser(id) {
      try {
        const res = await fetch("/api/session/select-user", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ user_id: id })
        });
        const data = await res.json();
        if (!res.ok) {
          this.error = data.error || "Failed to select user";
          return;
        }
        this.$emit("selected", data.user || null);
      } catch (err) {
        this.error = err.message || String(err);
      }
    }
  }
};
</script>
