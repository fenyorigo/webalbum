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
        <button class="viewer-btn" @click="copyLink" aria-label="Copy link">Copy link</button>
        <button class="viewer-btn" @click="downloadCurrent" aria-label="Download">Download</button>
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
          <img
            v-if="current && current.type === 'image'"
            :src="fileUrl(current.id)"
            :alt="fileName(current.path)"
            class="viewer-img"
          />
          <div v-else class="viewer-placeholder">Video preview not supported yet</div>
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
    </div>
  </div>
</template>

<script>
export default {
  name: "ImageViewer",
  props: {
    results: { type: Array, required: true },
    startId: { type: Number, required: true },
    isOpen: { type: Boolean, required: true },
    fileUrl: { type: Function, required: true }
  },
  emits: ["close"],
  data() {
    return {
      index: 0,
      lastFocused: null
    };
  },
  computed: {
    current() {
      return this.results[this.index] || null;
    }
  },
  watch: {
    isOpen(value) {
      if (value) {
        this.lastFocused = document.activeElement;
        document.body.style.overflow = "hidden";
        this.$nextTick(() => {
          this.setIndexFromId();
          this.preloadNeighbors();
          this.focusFirst();
        });
        window.addEventListener("keydown", this.onKeydown);
      } else {
        document.body.style.overflow = "";
        window.removeEventListener("keydown", this.onKeydown);
        if (this.lastFocused && this.lastFocused.focus) {
          this.lastFocused.focus();
        }
      }
    },
    startId() {
      if (this.isOpen) {
        this.setIndexFromId();
        this.preloadNeighbors();
      }
    },
    results() {
      if (this.isOpen) {
        this.setIndexFromId();
        this.preloadNeighbors();
      }
    },
    index() {
      this.preloadNeighbors();
    }
  },
  methods: {
    close() {
      this.$emit("close");
    },
    setIndexFromId() {
      const idx = this.results.findIndex((r) => r.id === this.startId);
      this.index = idx >= 0 ? idx : 0;
    },
    prev() {
      if (this.index > 0) {
        this.index -= 1;
      }
    },
    next() {
      if (this.index < this.results.length - 1) {
        this.index += 1;
      }
    },
    fileName(path) {
      const parts = path.split("/");
      return parts[parts.length - 1] || path;
    },
    copyLink() {
      if (!this.current) return;
      const url = `${window.location.origin}/api/file?id=${this.current.id}`;
      navigator.clipboard?.writeText(url).catch(() => {});
    },
    async downloadCurrent() {
      if (!this.current) return;
      const res = await fetch("/api/download", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids: [this.current.id] })
      });
      if (!res.ok) {
        return;
      }
      const blob = await res.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      const disposition = res.headers.get("Content-Disposition") || "";
      const match = disposition.match(/filename=\"?([^\";]+)\"?/);
      link.download = match ? match[1] : "webalbum-selected.zip";
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
    },
    preloadNeighbors() {
      const ids = [];
      if (this.results[this.index - 1]) ids.push(this.results[this.index - 1].id);
      if (this.results[this.index + 1]) ids.push(this.results[this.index + 1].id);
      ids.forEach((id) => {
        const img = new Image();
        img.src = this.fileUrl(id);
      });
    },
    onKeydown(event) {
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
