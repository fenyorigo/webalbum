import { createRouter, createWebHistory } from "vue-router";
import SearchPage from "./pages/SearchPage.vue";
import TagsPage from "./pages/TagsPage.vue";
import FavoritesPage from "./pages/FavoritesPage.vue";
import SavedSearchesPage from "./pages/SavedSearchesPage.vue";
import LoginPage from "./pages/LoginPage.vue";
import SetupPage from "./pages/SetupPage.vue";
import ProfilePage from "./pages/ProfilePage.vue";

const routes = [
  { path: "/login", component: LoginPage },
  { path: "/setup", component: SetupPage },
  { path: "/", component: SearchPage },
  { path: "/tags", component: TagsPage },
  { path: "/favorites", component: FavoritesPage },
  { path: "/saved-searches", component: SavedSearchesPage },
  { path: "/profile", component: ProfilePage }
];

const router = createRouter({
  history: createWebHistory(),
  routes
});

async function fetchSetupStatus() {
  try {
    const res = await fetch("/api/setup/status");
    if (!res.ok) {
      return { setup_required: false };
    }
    return await res.json();
  } catch (err) {
    return { setup_required: false };
  }
}

async function fetchMe() {
  try {
    const res = await fetch("/api/auth/me");
    if (!res.ok) {
      return null;
    }
    const data = await res.json();
    return data.user || null;
  } catch (err) {
    return null;
  }
}

router.beforeEach(async (to) => {
  if (to.path === "/setup") {
    const status = await fetchSetupStatus();
    if (!status.setup_required) {
      return "/login";
    }
    return true;
  }
  if (to.path === "/login") {
    return true;
  }
  const user = await fetchMe();
  if (!user) {
    const status = await fetchSetupStatus();
    if (status.setup_required) {
      return "/login";
    }
    return "/login";
  }
  return true;
});

export default router;
