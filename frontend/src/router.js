import { createRouter, createWebHistory } from "vue-router";
import SearchPage from "./pages/SearchPage.vue";
import TagsPage from "./pages/TagsPage.vue";

const routes = [
  { path: "/", component: SearchPage },
  { path: "/tags", component: TagsPage }
];

export default createRouter({
  history: createWebHistory(),
  routes
});
