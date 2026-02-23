<template>
  <div
    v-if="isOpen"
    class="viewer-backdrop"
    @click.self="close"
    role="dialog"
    aria-modal="true"
  >
    <div class="viewer-panel" ref="panel">
      <div class="viewer-bar">
        <button class="viewer-btn" @click="close" aria-label="Close">✕</button>
        <div class="viewer-title" :title="current?.path || ''">
          {{ fileName(current?.path || "") }}
        </div>
        <div class="viewer-count">{{ index + 1 }} / {{ results.length }}</div>
        <button class="viewer-btn" @click="togglePlay" aria-label="Play or pause">
          {{ isPlaying ? "Pause" : "Play" }}
        </button>
        <button class="viewer-btn" @click="stopPlayback" aria-label="Stop">Stop</button>
        <button v-if="canEditTags" class="viewer-btn" @click="rotateLeft" aria-label="Rotate counterclockwise">↺</button>
        <button v-if="canEditTags" class="viewer-btn" @click="rotateRight" aria-label="Rotate clockwise">↻</button>
        <button
          v-if="canEditTags && pendingQuarterTurns !== 0"
          class="viewer-btn"
          :disabled="rotateSaving"
          @click="saveRotation"
          aria-label="Save rotation"
        >
          Save
        </button>
        <button
          v-if="canEditTags"
          class="viewer-btn"
          @click="openEditor"
          aria-label="Edit tags"
        >
          Edit Tags
        </button>
        <button
          v-if="canTrash"
          class="viewer-btn danger"
          @click="moveToTrash"
          aria-label="Move to Trash"
        >
          Move to Trash
        </button>
      </div>

      <div class="viewer-body">
        <button
          class="nav-btn"
          :disabled="index <= 0"
          @click="prev"
          aria-label="Previous"
        >
          ‹
        </button>
        <div class="viewer-media">
          <video
            v-if="current"
            ref="video"
            class="viewer-video"
            controls
            preload="metadata"
            :src="videoSrc"
            :style="mediaTransformStyle"
            @play="isPlaying = true"
            @pause="isPlaying = false"
            @ended="isPlaying = false"
            @error="onMediaError"
          />
          <div v-else class="viewer-placeholder">Video not available</div>
        </div>
        <button
          class="nav-btn"
          :disabled="index >= results.length - 1"
          @click="next"
          aria-label="Next"
        >
          ›
        </button>
      </div>
      <div v-if="currentTags.length" class="viewer-tags" :title="currentTags.join(', ')">
        {{ currentTags.join(", ") }}
      </div>
      <div v-if="mediaError" class="viewer-badge">{{ mediaError }}</div>
      <div v-if="rotateSaving" class="viewer-badge">Rotate video, please wait</div>
      <div v-if="toast" class="viewer-inline-toast">{{ toast }}</div>
    </div>

    <div v-if="editOpen" class="modal-backdrop" @click.self="closeEditor">
      <div class="modal tag-editor-modal">
        <h3>Edit Tags</h3>
        <div class="tag-editor-chips">
          <span v-for="tag in editTags" :key="tag" class="tag-chip">
            {{ tag }}
            <button type="button" @click="removeEditTag(tag)" aria-label="Remove tag">✕</button>
          </span>
        </div>
        <label>
          Add tag
          <input
            v-model="editInput"
            type="text"
            placeholder="Type a tag and press Enter"
            @input="onEditInput"
            @keydown.enter.prevent="addEditTagFromInput"
          />
        </label>
        <div v-if="editSuggestions.length" class="suggestions">
          <button
            v-for="item in editSuggestions"
            :key="item.tag"
            type="button"
            class="suggestion"
            @click="addEditTag(item.tag)"
          >
            <span class="name">{{ item.tag }}</span>
            <span class="count">{{ item.cnt }}</span>
          </button>
        </div>
        <div class="modal-actions">
          <button class="inline" @click="saveEditTags" :disabled="editLoading">Save</button>
          <button class="inline" @click="closeEditor" :disabled="editLoading">Cancel</button>
        </div>
        <p v-if="editError" class="error">{{ editError }}</p>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "VideoViewer",
  props: {
    results: { type: Array, required: true },
    startId: { type: Number, required: true },
    isOpen: { type: Boolean, required: true },
    videoUrl: { type: Function, required: true },
    currentUser: { type: Object, default: null }
  },
  emits: ["close", "trashed", "open-asset", "open-image"],
  data() {
    return {
      index: 0,
      videoSrc: "",
      isPlaying: false,
      lastFocused: null,
      tagsById: {},
      editOpen: false,
      editInput: "",
      editTags: [],
      editSuggestions: [],
      editError: "",
      editLoading: false,
      suggestTimer: null,
      toast: "",
      mediaError: "",
      pendingQuarterTurns: 0,
      rotateVersion: 0,
      rotateSaving: false
    };
  },
  computed: {
    current() {
      return this.results[this.index] || null;
    },
    currentTags() {
      if (!this.current) {
        return [];
      }
      return this.tagsById[this.current.id] || [];
    },
    canEditTags() {
      const user = this.currentUser || window.__wa_current_user || null;
      return !!(user && user.is_admin);
    },
    canTrash() {
      return this.canEditTags;
    },
    mediaTransformStyle() {
      const deg = this.pendingQuarterTurns * 90;
      if (deg === 0) {
        return null;
      }
      return { transform: `rotate(${deg}deg)` };
    }
  },
  watch: {
    isOpen(value) {
      if (value) {
        this.lastFocused = document.activeElement;
        document.body.style.overflow = "hidden";
        this.$nextTick(() => {
          this.mediaError = "";
          this.setIndexFromId();
          this.loadCurrentVideo();
          this.fetchCurrentTags();
          this.focusFirst();
        });
        window.addEventListener("keydown", this.onKeydown);
      } else {
        this.stopPlayback(true);
        this.tagsById = {};
        this.closeEditor();
        document.body.style.overflow = "";
        window.removeEventListener("keydown", this.onKeydown);
        if (this.lastFocused && this.lastFocused.focus) {
          this.lastFocused.focus();
        }
      }
    },
    startId() {
      if (this.isOpen) {
        this.mediaError = "";
        this.setIndexFromId();
        this.loadCurrentVideo();
        this.fetchCurrentTags();
      }
    },
    results() {
      if (this.isOpen) {
        this.mediaError = "";
        this.setIndexFromId();
        this.loadCurrentVideo();
        this.fetchCurrentTags();
      }
    }
  },
  methods: {
    close() {
      this.stopPlayback(true);
      this.pendingQuarterTurns = 0;
      this.rotateVersion = 0;
      this.rotateSaving = false;
      this.$emit("close");
    },
    setIndexFromId() {
      const idx = this.results.findIndex((r) => r.id === this.startId);
      this.index = idx >= 0 ? idx : 0;
    },
    prev() {
      if (this.index <= 0) {
        return;
      }
      this.navigateToIndex(this.index - 1);
    },
    next() {
      if (this.index >= this.results.length - 1) {
        return;
      }
      this.navigateToIndex(this.index + 1);
    },
    navigateToIndex(targetIndex) {
      const row = this.results[targetIndex] || null;
      if (!row) {
        return;
      }
      if (row.type === "video") {
        this.stopPlayback(true);
        this.pendingQuarterTurns = 0;
        this.rotateVersion = 0;
        this.index = targetIndex;
        this.loadCurrentVideo();
        this.fetchCurrentTags();
        return;
      }
      this.stopPlayback(true);
      if (row.entity === "asset") {
        this.$emit("open-asset", row);
        return;
      }
      if (row.type === "image") {
        this.$emit("open-image", row.id);
        return;
      }
      this.showToast("Preview not supported for this file type");
    },
    fileName(path) {
      const parts = path.split("/");
      return parts[parts.length - 1] || path;
    },
    onMediaError() {
      this.mediaError = "Trashed";
    },
    loadCurrentVideo() {
      if (!this.current) {
        this.videoSrc = "";
        return;
      }
      const base = this.videoUrl(this.current.id);
      this.videoSrc = this.rotateVersion
        ? `${base}${base.includes("?") ? "&" : "?"}v=${this.rotateVersion}`
        : base;
      this.isPlaying = false;
      this.$nextTick(() => {
        const video = this.$refs.video;
        if (video) {
          video.pause();
          video.currentTime = 0;
        }
      });
    },
    togglePlay() {
      const video = this.$refs.video;
      if (!video) {
        return;
      }
      if (video.paused) {
        video.play().catch(() => {});
      } else {
        video.pause();
      }
    },
    rotateLeft() {
      this.pendingQuarterTurns -= 1;
      if (this.pendingQuarterTurns < -3) {
        this.pendingQuarterTurns += 4;
      }
    },
    rotateRight() {
      this.pendingQuarterTurns += 1;
      if (this.pendingQuarterTurns > 3) {
        this.pendingQuarterTurns -= 4;
      }
    },
    async saveRotation() {
      if (!this.current || this.pendingQuarterTurns === 0 || this.rotateSaving) {
        return;
      }
      this.rotateSaving = true;
      this.stopPlayback(true);
      try {
        const res = await fetch(`/api/media/${this.current.id}/rotate`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ quarter_turns: this.pendingQuarterTurns })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          this.toast = data.error || "Failed to rotate video";
          return;
        }
        this.pendingQuarterTurns = 0;
        this.rotateVersion = Date.now();
        this.loadCurrentVideo();
        this.toast = "Rotation saved";
        setTimeout(() => {
          this.toast = "";
        }, 2500);
      } catch (_e) {
        this.toast = "Failed to rotate video";
      } finally {
        this.rotateSaving = false;
      }
    },
    stopPlayback(releaseSrc = false) {
      const video = this.$refs.video;
      if (video) {
        video.pause();
        try {
          video.currentTime = 0;
        } catch {}
        if (releaseSrc) {
          video.removeAttribute("src");
          video.load();
        }
      }
      if (releaseSrc) {
        this.videoSrc = "";
      }
      this.isPlaying = false;
    },
    async moveToTrash() {
      if (!this.current || !this.canTrash) {
        return;
      }
      const relPath = this.current.path || this.fileName(this.current.path);
      const ok = window.confirm(`Move to Trash?\n${relPath}\nThis is reversible from Admin -> Trash.`);
      if (!ok) {
        return;
      }
      try {
        const res = await fetch("/api/admin/trash", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ id: this.current.id, type: this.current.type || "video" })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          this.toast = data.error || "Failed to move to trash";
          return;
        }
        this.$emit("trashed", { id: this.current.id });
        this.close();
      } catch (_e) {
        this.toast = "Failed to move to trash";
      }
    },
    async fetchCurrentTags() {
      if (!this.current || this.tagsById[this.current.id]) {
        return;
      }
      try {
        const res = await fetch(`/api/media/${this.current.id}/tags`);
        if (!res.ok) {
          this.tagsById = { ...this.tagsById, [this.current.id]: [] };
          return;
        }
        const data = await res.json();
        const tags = Array.isArray(data.tags) ? data.tags.filter((t) => typeof t === "string" && t) : [];
        this.tagsById = { ...this.tagsById, [this.current.id]: tags };
      } catch (_e) {
        this.tagsById = { ...this.tagsById, [this.current.id]: [] };
      }
    },
    openEditor() {
      this.editError = "";
      this.editInput = "";
      this.editSuggestions = [];
      this.editTags = [...this.currentTags];
      this.editOpen = true;
    },
    closeEditor() {
      this.editOpen = false;
      this.editLoading = false;
      this.editError = "";
      this.editInput = "";
      this.editSuggestions = [];
      if (this.suggestTimer) {
        clearTimeout(this.suggestTimer);
        this.suggestTimer = null;
      }
    },
    normalizeTag(raw) {
      if (typeof raw !== "string") {
        return "";
      }
      return raw.trim().replace(/\s+/g, " ");
    },
    validateTag(tag) {
      if (!tag) {
        return "Tag cannot be empty";
      }
      if (tag.length > 128) {
        return "Tag too long (max 128)";
      }
      if (tag.includes("|")) {
        return "Tag must not contain pipe character";
      }
      return "";
    },
    addEditTagFromInput() {
      this.addEditTag(this.editInput);
    },
    addEditTag(raw) {
      const tag = this.normalizeTag(raw);
      const err = this.validateTag(tag);
      if (err) {
        this.editError = err;
        return;
      }
      if (!this.editTags.includes(tag)) {
        this.editTags.push(tag);
      }
      this.editError = "";
      this.editInput = "";
      this.editSuggestions = [];
    },
    removeEditTag(tag) {
      this.editTags = this.editTags.filter((t) => t !== tag);
    },
    onEditInput() {
      this.editError = "";
      if (this.suggestTimer) {
        clearTimeout(this.suggestTimer);
      }
      const q = this.normalizeTag(this.editInput);
      if (q.length < 2) {
        this.editSuggestions = [];
        return;
      }
      this.suggestTimer = setTimeout(() => {
        this.fetchSuggestions(q);
      }, 150);
    },
    async fetchSuggestions(q) {
      try {
        const params = new URLSearchParams({ q, limit: "12" });
        const res = await fetch(`/api/tags?${params.toString()}`);
        if (!res.ok) {
          this.editSuggestions = [];
          return;
        }
        const data = await res.json();
        if (!Array.isArray(data)) {
          this.editSuggestions = [];
          return;
        }
        this.editSuggestions = data
          .filter((item) => item && typeof item.tag === "string")
          .map((item) => ({ tag: item.tag, cnt: Number(item.cnt || 0) }))
          .filter((item) => !this.editTags.includes(item.tag));
      } catch (_e) {
        this.editSuggestions = [];
      }
    },
    async saveEditTags() {
      if (!this.current) {
        return;
      }
      this.editLoading = true;
      this.editError = "";
      try {
        const normalized = this.editTags.map((t) => this.normalizeTag(t));
        for (const t of normalized) {
          const err = this.validateTag(t);
          if (err) {
            this.editError = err;
            this.editLoading = false;
            return;
          }
        }
        const res = await fetch(`/api/media/${this.current.id}/tags`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ tags: normalized })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
          this.editError = data.error || "Failed to save tags";
          this.editLoading = false;
          return;
        }
        const tags = Array.isArray(data.tags) ? data.tags : normalized;
        this.tagsById = { ...this.tagsById, [this.current.id]: tags };
        this.closeEditor();
        this.toast = "Tags saved. Reindex later to propagate to all searches.";
        setTimeout(() => {
          this.toast = "";
        }, 2500);
      } catch (_e) {
        this.editError = "Failed to save tags";
      } finally {
        this.editLoading = false;
      }
    },
    onKeydown(event) {
      if (this.editOpen) {
        if (event.key === "Escape") {
          event.preventDefault();
          this.closeEditor();
        }
        return;
      }
      if (event.key === "Escape") {
        this.close();
        return;
      }
      if (event.key === "ArrowLeft") {
        this.prev();
        return;
      }
      if (event.key === "ArrowRight") {
        this.next();
        return;
      }
      if (event.key === " " || event.code === "Space") {
        event.preventDefault();
        this.togglePlay();
        return;
      }
      if (event.key === "Tab") {
        this.trapFocus(event);
      }
    },
    focusableElements() {
      const root = this.$refs.panel;
      if (!root) return [];
      return Array.from(
        root.querySelectorAll(
          "button, [href], input, select, textarea, [tabindex]:not([tabindex='-1'])"
        )
      );
    },
    focusFirst() {
      const focusables = this.focusableElements();
      if (focusables.length) {
        focusables[0].focus();
      }
    },
    trapFocus(event) {
      const focusables = this.focusableElements();
      if (focusables.length === 0) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        last.focus();
        event.preventDefault();
      } else if (!event.shiftKey && document.activeElement === last) {
        first.focus();
        event.preventDefault();
      }
    }
  }
};
</script>

<style scoped>
.tag-editor-modal {
  width: min(640px, 92vw);
}

.tag-editor-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.tag-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #efe2c9;
  color: #2b2b2b;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 13px;
}

.tag-chip button {
  border: none;
  background: transparent;
  cursor: pointer;
  color: #2b2b2b;
}

.viewer-badge {
  position: absolute;
  left: 16px;
  bottom: 16px;
  background: #a84a3a;
  color: #fff;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
}

.viewer-inline-toast {
  position: absolute;
  right: 16px;
  bottom: 16px;
  background: #1e1e1e;
  color: #fff;
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
}

.viewer-btn.danger {
  border-color: #a84a3a;
  color: #a84a3a;
}
</style>
