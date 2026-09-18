/**
 * Wishlist Manager
 * Database-backed wishlist using WordPress AJAX (replaces localStorage)
 */

(function () {
  "use strict";

  // Wishlist Manager Object
  window.WishlistManager = {
    /**
     * Get wishlist from server
     * @returns {Promise<Array>} Promise resolving to array of product IDs
     */
    getWishlist: function () {
      return new Promise(function (resolve, reject) {
        // If not logged in, return empty array
        if (!wishlistData.isLoggedIn) {
          resolve([]);
          return;
        }

        // Check cache first
        const cacheKey = "sts_wishlist_client_" + wishlistData.userId;
        const cached = sessionStorage.getItem(cacheKey);
        if (cached) {
          try {
            const data = JSON.parse(cached);
            // Cache valid for 5 minutes
            if (data.timestamp && Date.now() - data.timestamp < 300000) {
              resolve(data.products || []);
              return;
            }
          } catch (e) {
            // Invalid cache, continue to fetch
          }
        }

        // Fetch from server
        jQuery.ajax({
          url: wishlistData.ajaxUrl,
          type: "POST",
          data: {
            action: "sts_wishlist_get_products",
            nonce: wishlistData.nonce,
          },
          success: function (response) {
            if (response.success && response.data && response.data.products) {
              const productIds = response.data.products.map(function (p) {
                return parseInt(p.id);
              });

              // Cache result
              sessionStorage.setItem(
                cacheKey,
                JSON.stringify({
                  products: productIds,
                  timestamp: Date.now(),
                })
              );

              resolve(productIds);
            } else {
              resolve([]);
            }
          },
          error: function () {
            resolve([]);
          },
        });
      });
    },

    /**
     * Check if product is in wishlist
     * @param {number|string} productId
     * @returns {Promise<boolean>}
     */
    isInWishlist: function (productId) {
      const self = this;
      return this.getWishlist().then(function (wishlist) {
        return wishlist.includes(parseInt(productId));
      });
    },

    /**
     * Add product to wishlist
     * @param {number|string} productId
     * @returns {Promise<boolean>} Success status
     */
    addToWishlist: function (productId) {
      const self = this;
      productId = parseInt(productId);

      return new Promise(function (resolve, reject) {
        if (!wishlistData.isLoggedIn) {
          // Redirect to login or show message
          if (typeof showToast === "function") {
            showToast("יש להתחבר כדי להוסיף למועדפים", "error");
          } else {
            alert("יש להתחבר כדי להוסיף למועדפים");
          }
          resolve(false);
          return;
        }

        // Show loading state
        self.setLoadingState(productId, true);

        jQuery.ajax({
          url: wishlistData.ajaxUrl,
          type: "POST",
          data: {
            action: "sts_wishlist_add",
            nonce: wishlistData.nonce,
            product_id: productId,
          },
          success: function (response) {
            // Hide loading state
            self.setLoadingState(productId, false);

            if (response.success) {
              // Clear cache to force refresh
              const cacheKey = "sts_wishlist_client_" + wishlistData.userId;
              sessionStorage.removeItem(cacheKey);

              self.updateUI(productId, true);

              // Show success toast
              if (typeof showToast === "function") {
                showToast("המוצר נוסף למועדפים", "success");
              }

              // Update wishlist icon count
              if (typeof updateWishlistIcon === "function") {
                const count = response.data.count || 0;
                updateWishlistIcon(count > 0);
              }

              resolve(true);
            } else {
              // Show error toast
              if (typeof showToast === "function") {
                showToast(
                  response.data.message || "שגיאה בהוספה למועדפים",
                  "error"
                );
              } else {
                alert(response.data.message || "שגיאה בהוספה למועדפים");
              }
              resolve(false);
            }
          },
          error: function () {
            // Hide loading state
            self.setLoadingState(productId, false);

            // Show error toast
            if (typeof showToast === "function") {
              showToast("שגיאה בהוספה למועדפים", "error");
            } else {
              alert("שגיאה בהוספה למועדפים");
            }
            resolve(false);
          },
        });
      });
    },

    /**
     * Remove product from wishlist
     * @param {number|string} productId
     * @returns {Promise<boolean>} Success status
     */
    removeFromWishlist: function (productId) {
      const self = this;
      productId = parseInt(productId);

      return new Promise(function (resolve, reject) {
        if (!wishlistData.isLoggedIn) {
          resolve(false);
          return;
        }

        // Show loading state
        self.setLoadingState(productId, true);

        jQuery.ajax({
          url: wishlistData.ajaxUrl,
          type: "POST",
          data: {
            action: "sts_wishlist_remove",
            nonce: wishlistData.nonce,
            product_id: productId,
          },
          success: function (response) {
            // Hide loading state
            self.setLoadingState(productId, false);

            if (response.success) {
              // Clear cache
              const cacheKey = "sts_wishlist_client_" + wishlistData.userId;
              sessionStorage.removeItem(cacheKey);

              self.updateUI(productId, false);

              // Show success toast
              if (typeof showToast === "function") {
                showToast("המוצר הוסר מהמועדפים", "success");
              }

              // Update wishlist icon count
              if (typeof updateWishlistIcon === "function") {
                const count = response.data.count || 0;
                updateWishlistIcon(count > 0);
              }

              resolve(true);
            } else {
              // Show error toast
              if (typeof showToast === "function") {
                showToast(
                  response.data.message || "שגיאה בהסרה מהמועדפים",
                  "error"
                );
              }
              resolve(false);
            }
          },
          error: function () {
            // Hide loading state
            self.setLoadingState(productId, false);

            // Show error toast
            if (typeof showToast === "function") {
              showToast("שגיאה בהסרה מהמועדפים", "error");
            }
            resolve(false);
          },
        });
      });
    },

    /**
     * Toggle product in wishlist
     * @param {number|string} productId
     * @returns {Promise<boolean>} New state (true = in wishlist, false = not in wishlist)
     */
    toggleWishlist: function (productId) {
      const self = this;
      productId = parseInt(productId);

      return new Promise(function (resolve, reject) {
        if (!wishlistData.isLoggedIn) {
          if (typeof showToast === "function") {
            showToast("יש להתחבר כדי להוסיף למועדפים", "error");
          } else {
            alert("יש להתחבר כדי להוסיף למועדפים");
          }
          resolve(false);
          return;
        }

        // Show loading state
        self.setLoadingState(productId, true);

        jQuery.ajax({
          url: wishlistData.ajaxUrl,
          type: "POST",
          data: {
            action: "sts_wishlist_toggle",
            nonce: wishlistData.nonce,
            product_id: productId,
          },
          success: function (response) {
            // Hide loading state
            self.setLoadingState(productId, false);

            if (response.success) {
              // Clear cache
              const cacheKey = "sts_wishlist_client_" + wishlistData.userId;
              sessionStorage.removeItem(cacheKey);

              const inWishlist = response.data.in_wishlist || false;
              self.updateUI(productId, inWishlist);

              // Show toast notification
              if (typeof showToast === "function") {
                const message = inWishlist
                  ? "המוצר נוסף למועדפים"
                  : "המוצר הוסר מהמועדפים";
                showToast(message, "success");
              }

              // Update wishlist icon count
              if (typeof updateWishlistIcon === "function") {
                const count = response.data.count || 0;
                updateWishlistIcon(count > 0);
              }

              resolve(inWishlist);
            } else {
              // Show error toast
              if (typeof showToast === "function") {
                showToast(
                  response.data.message || "שגיאה בעדכון המועדפים",
                  "error"
                );
              }
              resolve(false);
            }
          },
          error: function () {
            // Hide loading state
            self.setLoadingState(productId, false);

            // Show error toast
            if (typeof showToast === "function") {
              showToast("שגיאה בעדכון המועדפים", "error");
            }
            resolve(false);
          },
        });
      });
    },

    /**
     * Get wishlist count
     * @returns {Promise<number>}
     */
    getWishlistCount: function () {
      return this.getWishlist().then(function (wishlist) {
        return wishlist.length;
      });
    },

    /**
     * Set loading state for wishlist button
     * @param {number} productId
     * @param {boolean} isLoading
     */
    setLoadingState: function (productId, isLoading) {
      const buttons = document.querySelectorAll(
        `.product-card-wishlist-toggle[data-product-id="${productId}"]`
      );
      buttons.forEach((button) => {
        if (isLoading) {
          button.classList.add("wishlist-loading");
          button.disabled = true;
        } else {
          button.classList.remove("wishlist-loading");
          button.disabled = false;
        }
      });
    },

    /**
     * Update UI for a specific product
     * @param {number} productId
     * @param {boolean} inWishlist
     */
    updateUI: function (productId, inWishlist) {
      const buttons = document.querySelectorAll(
        `.product-card-wishlist-toggle[data-product-id="${productId}"]`
      );
      buttons.forEach((button) => {
        const container = button.querySelector(
          ".product-card-wishlist-toggle-container"
        );
        if (inWishlist) {
          button.classList.add("in-wishlist");
          if (container) {
            container.classList.add("has-items");
          }
        } else {
          button.classList.remove("in-wishlist");
          if (container) {
            container.classList.remove("has-items");
          }
        }
      });

      // Update wishlist icon
      if (typeof updateWishlistIcon === "function") {
        this.getWishlistCount().then(function (count) {
          updateWishlistIcon(count > 0);
        });
      }
    },

    /**
     * Initialize all wishlist buttons on the page
     */
    initButtons: function () {
      const self = this;
      const buttons = document.querySelectorAll(
        ".product-card-wishlist-toggle"
      );

      buttons.forEach((button) => {
        const productId = button.getAttribute("data-product-id");

        // Set initial state asynchronously
        this.isInWishlist(productId).then(function (inWishlist) {
          if (inWishlist) {
            button.classList.add("in-wishlist");
            const container = button.querySelector(
              ".product-card-wishlist-toggle-container"
            );
            if (container) {
              container.classList.add("has-items");
            }
          }
        });

        // Add click event
        button.addEventListener("click", function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.toggleWishlist(productId);
        });
      });
    },
  };

  // Initialize when DOM is ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      WishlistManager.initButtons();

      // Initialize wishlist icon count
      if (wishlistData.isLoggedIn && typeof updateWishlistIcon === "function") {
        WishlistManager.getWishlistCount().then(function (count) {
          updateWishlistIcon(count > 0);
        });
      }
    });
  } else {
    WishlistManager.initButtons();

    // Initialize wishlist icon count
    if (wishlistData.isLoggedIn && typeof updateWishlistIcon === "function") {
      WishlistManager.getWishlistCount().then(function (count) {
        updateWishlistIcon(count > 0);
      });
    }
  }
})();
