<template>
  <div id="app">
    <nav class="top">
      <div class="brand">
        Webalbum
        <span class="version">v0.1.0</span>
      </div>
      <div class="links">
        <router-link to="/" class="link" active-class="active" exact-active-class="active">Search</router-link>
        <router-link to="/tags" class="link" active-class="active">Tags</router-link>
      </div>
    </nav>
    <router-view />
  </div>
</template>

<script>
export default {
  name: "App"
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
  max-width: 980px;
  margin: 40px auto 80px;
  padding: 0 20px;
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

.link {
  color: var(--accent);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  padding-bottom: 2px;
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
</style>
