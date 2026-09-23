/**
 * MaradigmaBookingModal
 * Booking modal controller (class-based).
 *
 * Depends on:
 * - window.MaradigmaApiClient
 * - window.MaradigmaEvents (namespaced)
 * - window.MaradigmaI18n (injected via wp_add_inline_script BEFORE this file)
 *
 * HTML expected within .md-booking:
 * - [data-md-modal]
 * - [data-md-open]
 * - [data-md-close] (can be multiple)
 * - [data-md-info], [data-md-error]
 * - [data-md-step-title], [data-md-step-container]
 * - [data-md-back], [data-md-next]
 * - [data-md-step-pill="1..4"]
 *
 * Config via data-attrs on wrapper:
 * - data-price-on-booking-endpoint (POST /maradigma/v1/boat/price-on-booking)
 * - data-booking-online-endpoint (POST /maradigma/v1/booking/online)
 * - data-countries-endpoint (GET /maradigma/v1/countries)
 * - data-boat-id (optional, can be set on the fly)
 */
class MaradigmaBookingModal {
	constructor(wrapper, options = {}) {
		this.wrapper = wrapper;
		this.options = options;

		this.globalCfg = window.MaradigmaConfig || {};

		this.modal = this._qs("[data-md-modal]");
		this.overlay = this._qs(".md-modal__overlay");
		this.openBtn = this._qs("[data-md-open]");
		this.infoBox = this._qs("[data-md-info]");
		this.errBox = this._qs("[data-md-error]");
		this.stepTitleEl = this._qs("[data-md-step-title]");
		this.stepContainer = this._qs("[data-md-step-container]");
		this.backBtn = this._qs("[data-md-back]");
		this.nextBtn = this._qs("[data-md-next]");

		this._isOpening = false;
		this._isOpen = false;
		this._lastFocusedBeforeOpen = null;

		this._boundInputHandler = null;
		this._boundChangeHandler = null;
		this._boundKeydownHandler = null;
		this._boundToggleClickHandler = null;
		this._bookingOpenBusBound = false;
		this._modalPortalPlaceholder = null;
		this._modalOriginalParent = null;

		this.api = new MaradigmaApiClient({
			nonce: "",
			timeoutMs: options.timeoutMs ?? this.globalCfg.timeoutMs ?? 15000,
			wpJsonBase: options.wpJsonBase ?? this.globalCfg.wpJsonBase ?? "/wp-json",
		});
		this.bookingTimeoutMs = this._toInt(
			options.bookingTimeoutMs ?? this.globalCfg.bookingTimeoutMs ?? 45000,
			45000
		);

		this.cfg = this._getCfg();
		this.i18n = this._getI18n();

		this.stepsCfg = {
			numSteps: 4,
			steps: {
				1: { step: 1, title: this._t("steps.step1", "Specify the reservation") },
				2: { step: 2, title: this._t("steps.step2", "Your profile information") },
				3: { step: 3, title: this._t("steps.step3", "Payment method") },
				4: { step: 4, title: this._t("steps.step4", "Reservation confirmed") },
			},
		};

		this.state = {
			currentStep: 1,
			quote: null,
			boat_capacity: null,
			boat_data: null,

			uuid_shop_cart: "",
			bookingOnline: null,

			ui: {
				termsOpen: false,
			},

			api: null,

			form: {
				date_start: "",
				date_end: "",
				full_day: false,
				timeslot_id: null,
				timeslot: null,
				people: 1,
				children_included: false,
				pax_children: 0,
				hire_skipper: "no",
				message: "",
				first_name: "",
				last_name: "",
				email: "",
				country: "",
				phone: "",
				phone_raw: "",
				payment_method: "",
				accept_terms: false,
				additionals_selected: {},
			},

			paymentReturn: {
				booking: null,
				payment: null,
				customer: null,
				booking_restore: null,
				is_paid: false,
			},
		};

		this._bookingStepBusy = false;

		this._fpStep1 = null;

		this.priceTimer = null;
		this.priceReqId = 0;
		this.priceAbort = null;
		this.lastPriceKeyDone = "";
		this.lastPriceKeyQueued = "";

		this._boatCache = {};
		this._boatCacheTtlMs = 60 * 1000;

		this._lastQuoteOk = null;

		this._iti = null;
		this._step2PhoneEl = null;
		this._step2CountryEl = null;

		this._countriesCache = null;
		this._countriesCacheLang = "";
		this._countriesCacheTtlMs = 24 * 60 * 60 * 1000;
		this._countriesCacheTs = 0;
	}

	async init() {
		if (!this.wrapper) {
			return;
		}

		if (this.wrapper.__mdBookingMounted) {
			return;
		}

		if (!this.modal || !this.openBtn) {
			return;
		}

		this.wrapper.__mdBookingMounted = true;
		this.wrapper.__mdBookingInstance = this;
		this.wrapper.dataset.mdReady = "0";

		this._setOpenButtonReady(false);

		this._boundInputHandler = (e) => {
			this._onFieldEvent(e);
		};

		this._boundChangeHandler = (e) => {
			this._onFieldEvent(e);
		};

		this._ensureModalPortal();

		this.wrapper.addEventListener("input", this._boundInputHandler, true);
		this.wrapper.addEventListener("change", this._boundChangeHandler, true);
		if (this.modal && this.modal !== this.wrapper) {
			this.modal.addEventListener("input", this._boundInputHandler, true);
			this.modal.addEventListener("change", this._boundChangeHandler, true);
		}

		this._qsa("[data-md-close]").forEach((btn) => {
			btn.addEventListener("click", (e) => {
				if (e && e.preventDefault) {
					e.preventDefault();
				}

				this.close();
			});
		});

		if (this.overlay) {
			this.overlay.addEventListener("click", (e) => {
				if (e && e.preventDefault) {
					e.preventDefault();
				}

				const backdropMode = String(this.modal.getAttribute("data-md-backdrop") || "")
					.trim()
					.toLowerCase();

				const backdropCloseAttr = String(this.modal.getAttribute("data-md-backdrop-close") || "true")
					.trim()
					.toLowerCase();

				const allowBackdropClose =
					backdropMode !== "static" &&
					backdropCloseAttr !== "false";

				if (allowBackdropClose) {
					this.close();
				}
			});
		}

		if (!this._boundKeydownHandler) {
			this._boundKeydownHandler = (e) => {
				const isModalOpen = !!this.modal?.classList?.contains("is-open");

				if (e.key !== "Escape" || !this.modal || !isModalOpen) {
					return;
				}

				const backdropMode = String(this.modal.getAttribute("data-md-backdrop") || "")
					.trim()
					.toLowerCase();

				const keyboardAttr = String(this.modal.getAttribute("data-md-keyboard") || "true")
					.trim()
					.toLowerCase();

				const allowKeyboardClose =
					backdropMode !== "static" &&
					keyboardAttr !== "false";

				if (allowKeyboardClose) {
					this.close();
				}
			};

			document.addEventListener("keydown", this._boundKeydownHandler);
		}

		if (this.nextBtn) {
			this.nextBtn.addEventListener("click", (e) => {
				if (e && e.preventDefault) {
					e.preventDefault();
				}

				this.next();
			});
		}

		if (this.backBtn) {
			this.backBtn.addEventListener("click", (e) => {
				if (e && e.preventDefault) {
					e.preventDefault();
				}

				this.back();
			});
		}

		if (!this._boundToggleClickHandler) {
			this._boundToggleClickHandler = (e) => {
				const btn = e?.target?.closest?.("[data-md-toggle]");
				if (!btn) {
					return;
				}

				const key = btn.getAttribute("data-md-toggle");
				if (!key) {
					return;
				}

				const panel = this._qs(`[data-md-toggle-panel="${key}"]`);
				if (!panel) {
					return;
				}

				const isOpen = panel.getAttribute("data-md-open") === "1";

				panel.setAttribute("data-md-open", isOpen ? "0" : "1");
				btn.setAttribute("aria-expanded", isOpen ? "false" : "true");
			};
		}

		this.wrapper.addEventListener("click", this._boundToggleClickHandler, true);
		if (this.modal && this.modal !== this.wrapper) {
			this.modal.addEventListener("click", this._boundToggleClickHandler, true);
		}

		if (window.MaradigmaEvents && typeof window.MaradigmaEvents.on === "function" && !this._bookingOpenBusBound) {
			this._bookingOpenBusBound = true;

			MaradigmaEvents.on("booking:open", (e) => {
				const detail = e?.detail || {};
				const boatId = detail.boatId ?? "";
				const boatData = detail.boatData ?? null;

				if (boatId) {
					this.cfg.boatId = String(boatId);
					this.wrapper.setAttribute("data-boat-id", String(boatId));
				}

				if (boatData && typeof boatData === "object") {
					this.state.boat_data = boatData;

					const cap = this._extractBoatCapacity(boatData);
					if (cap) {
						this._applyCapacity(cap);
					}
				}

				const readyAttr = this.openBtn?.getAttribute("data-md-open-ready");

				if (readyAttr !== "1") {
					return;
				}

				if (this._isOpening || this._isOpen) {
					return;
				}

				void this.open();
			});
		}

		this.wrapper.dataset.mdReady = "1";
		this._setOpenButtonReady(true);

		try {
			void this._handleReturnFromPayment();
		} catch {
			// noop
		}
	}

	async open() {
		if (this._isOpening || this._isOpen) {
			return;
		}

		this._isOpening = true;
		this._setOpenButtonBusy(true);

		try {
			this._hide(this.infoBox);
			this._hide(this.errBox);
			this._clearInlineErrors();

			this.state.currentStep = 1;

			if (this.cfg.showChildrenIncluded !== true) {
				this.state.form.children_included = false;
				this.state.form.pax_children = 0;
			}

			this._applyDefaultDatesOnOpen();
			this._openModal();
			this._isOpen = true;
			this._scrollModalBodyToTop("auto");

			this.lastPriceKeyQueued = "";
			this.lastPriceKeyDone = "";

			this.render();

			await this._hydrateBoatOnOpen({ forceRefresh: true }).catch(() => { });
			if (this.state.currentStep === 1) {
				this.render();
				this.schedulePriceOnBooking();
			}

			void this._getCountries(this.cfg?.lang || "EN").catch(() => { });
		} finally {
			this._isOpening = false;
			this._setOpenButtonBusy(false);
		}
	}

	close() {
		this._destroyIntlTelInputAndCountry();
		this._closeModal();
		this._isOpen = false;
		this._isOpening = false;
		this._setOpenButtonBusy(false);
	}

	async next() {
		console.group("[Booking] next()");

		this._hide(this.errBox);
		this._hide(this.infoBox);
		this._clearInlineErrors();

		const step = this.state.currentStep;
		const isValidStep = this._validateStep(step);


		if (!isValidStep) {
			console.warn("next() stopped: invalid step");
			console.groupEnd();
			return;
		}

		if (this._bookingStepBusy) {
			console.warn("next() stopped: booking step busy");
			console.groupEnd();
			return;
		}

		if (step === 1 || step === 2) {
			try {
				this._setNavigationBusy(true);


				await this._syncBookingOnlineStep(step);

				this.state.currentStep++;

				this.render();
				console.groupEnd();
				return;
			} catch (e) {
				console.error("error in _syncBookingOnlineStep:", e);
				this._showBookingRequestError(e);
				console.groupEnd();
				return;
			} finally {
				this._setNavigationBusy(false);
			}
		}

		if (step === 3) {
			await this._startOnlinePaymentFlow();
			console.groupEnd();
			return;
		}

		if (step === 4) {
			const bookingUrl = this.state?.paymentReturn?.booking?.url || "";


			if (bookingUrl) {
				window.open(bookingUrl, "_blank", "noopener,noreferrer");
			} else {
				this.close();
			}

			console.groupEnd();
			return;
		}
	}

	back() {
		console.group("[Booking] back()");

		this._hide(this.errBox);
		this._hide(this.infoBox);
		this._clearInlineErrors();

		if (this.state.currentStep <= 1) {
			console.warn("already on first step");
			console.groupEnd();
			return;
		}

		this.state.currentStep--;

		this.render();

		console.groupEnd();
	}

	render() {
		this._hide(this.infoBox);
		this._hide(this.errBox);
		this._clearInlineErrors();

		const step = this.state.currentStep;

		if (!this.stepContainer) return;

		if (step === 2) {
			this._destroyIntlTelInputAndCountry();
		}

		let html = "";
		if (step === 1) html = this._renderStep1();
		if (step === 2) html = this._renderStep2();
		if (step === 3) html = this._renderStep3();
		if (step === 4) html = this._renderStep4();

		this.stepContainer.innerHTML = html;
		this._scrollModalBodyToTop("auto");

		if (step === 1) {
			this._bindFlatpickrStep1();
			this._renderOrUpdateSkipperPrompt();

			const tsWrap = this.stepContainer?.querySelector('[data-md-timeslot-wrap="1"]');
			if (tsWrap) {
				tsWrap.innerHTML = this._renderTimeslotSelectorIfNeeded();
			}
		}

		if (step === 2) {
			this._bindStep2CountryAndPhone();
		}

		if (step === 3) {
			this._bindStep3Events(this.stepContainer);
		}

		this._updateProgress(step);

		this._syncStepTitlePresentation(step);

		const backIcon = (typeof window.MaradigmaIcons !== "undefined")
			? window.MaradigmaIcons.renderIcon("chevron-left", {
				className: "md-btn__icon md-btn__icon--left",
				width: 16,
				height: 16
			})
			: "";

		const nextIcon = (typeof window.MaradigmaIcons !== "undefined")
			? window.MaradigmaIcons.renderIcon("chevron-right", {
				className: "md-btn__icon md-btn__icon--right",
				width: 16,
				height: 16
			})
			: "";

		const cancelIcon = (typeof window.MaradigmaIcons !== "undefined")
			? window.MaradigmaIcons.renderIcon("xmark", {
				className: "md-btn__icon md-btn__icon--left",
				width: 14,
				height: 14
			})
			: "";

		const headerCloseBtn = this.modal?.querySelector(".md-modal__close[data-md-close]");
		const footerCloseBtn = this.modal?.querySelector('.md-modal__footer [data-md-close]');
		const bookingUrl = this.state?.paymentReturn?.booking?.url || "";

		const quoteUi = this._extractSidebarQuoteData();
		const amountFormatted =
			quoteUi?.payOnlineFormatted ||
			quoteUi?.totalFormatted ||
			"";

		if (this.backBtn) {
			this.backBtn.classList.add("md-footer__back");

			const mustHideBackButton = step <= 1 || step === 4;
			this.backBtn.classList.toggle("md-d-none", mustHideBackButton);

			if (!mustHideBackButton) {
				this.backBtn.innerHTML = `
        ${backIcon}
        <span>${this._t("ui.back", "Back")}</span>
      `;
			} else {
				this.backBtn.innerHTML = "";
			}
		}

		if (headerCloseBtn) {
			headerCloseBtn.style.display = "";
		}

		if (footerCloseBtn) {
			footerCloseBtn.classList.add("md-footer__cancel");
			footerCloseBtn.style.display = "";

			if (step === 4) {
				footerCloseBtn.innerHTML = `
        ${cancelIcon}
        <span>${this._t("ui.close", "Close")}</span>
      `;
			} else {
				footerCloseBtn.innerHTML = `
        ${cancelIcon}
        <span>${this._t("ui.cancel", "Cancel")}</span>
      `;
			}
		}

		if (this.nextBtn) {
			this.nextBtn.classList.add("md-footer__next");

			if (step === 3) {
				const confirmAndPayText = this._t("ui.confirmAndPay", "Confirm and pay");
				const buttonText = amountFormatted
					? `${confirmAndPayText} · ${amountFormatted}`
					: confirmAndPayText;

				this.nextBtn.innerHTML = `
        <span>${this._esc(buttonText)}</span>
        ${nextIcon}
      `;
				this.nextBtn.style.display = "";
				this.nextBtn.disabled = false;
			} else if (step === 4) {
				if (bookingUrl) {
					this.nextBtn.innerHTML = `
          <span>${this._t("ui.viewBookingDetails", "View booking details")}</span>
          ${nextIcon}
        `;
					this.nextBtn.style.display = "";
					this.nextBtn.disabled = false;
				} else {
					this.nextBtn.style.display = "none";
					this.nextBtn.innerHTML = "";
					this.nextBtn.disabled = false;
				}
			} else {
				this.nextBtn.innerHTML = `
        <span>${this._t("ui.continue", "Continue")}</span>
        ${nextIcon}
      `;
				this.nextBtn.style.display = "";
				this.nextBtn.disabled = false;
			}
		}
	}

	handleOpenTrigger(e = null) {
		if (e && typeof e.preventDefault === "function") {
			e.preventDefault();
		}

		if (e && typeof e.stopPropagation === "function") {
			e.stopPropagation();
		}

		const readyAttr = this.openBtn?.getAttribute("data-md-open-ready");
		if (readyAttr !== "1") {
			return;
		}

		if (this._isOpening || this._isOpen) {
			return;
		}

		void this.open();
	}

	_renderCurrentStep() {
		const step = this.state.currentStep;
		if (!this.stepContainer) return;

		this._hide(this.infoBox);
		this._hide(this.errBox);
		this._clearInlineErrors();

		if (step === 2) {
			this._destroyIntlTelInputAndCountry();
		}

		let html = "";
		if (step === 1) html = this._renderStep1();
		if (step === 2) html = this._renderStep2();
		if (step === 3) html = this._renderStep3();
		if (step === 4) html = this._renderStep4();

		this.stepContainer.innerHTML = html;
		this._scrollModalBodyToTop("auto");

		if (step === 1) {
			this._bindFlatpickrStep1();
			this._renderOrUpdateSkipperPrompt();

			const tsWrap = this.stepContainer?.querySelector('[data-md-timeslot-wrap="1"]');
			if (tsWrap) {
				tsWrap.innerHTML = this._renderTimeslotSelectorIfNeeded();
			}
		}

		if (step === 2) {
			this._bindStep2CountryAndPhone();
		}

		if (step === 3) {
			this._bindStep3Events(this.stepContainer);
		}

		this._syncStepTitlePresentation(step);
	}

	async _syncBookingOnlineStep(step) {
		if (!this.cfg.bookingOnlineEndpoint) {
			throw new Error(this._t("errors.bookingEndpointMissing", "Booking endpoint not configured."));
		}

		const returnUrl = this._buildReturnUrlAfterPayment?.() || window.location.href;
		const payload = this._buildBookingOnlinePayload(step, returnUrl);

		const res = await this.api.postJson(
			this.cfg.bookingOnlineEndpoint,
			payload,
			{ timeoutMs: this.bookingTimeoutMs }
		);

		const isJsonError =
			(res && typeof res === "object" && String(res.status || "").toLowerCase() === "error") ||
			(res && typeof res === "object" && res.success === false) ||
			(res && typeof res === "object" && res.error && (res.error.message || res.error.code));

		if (isJsonError) {
			const msg =
				res?.message ||
				res?.error?.message ||
				this._t("errors.bookingError", "Booking error");
			throw new Error(String(msg));
		}

		const uuid =
			res?.result?.uuid_shop_cart ||
			res?.uuid_shop_cart ||
			res?.data?.uuid_shop_cart ||
			"";

		if (uuid) {
			this.state.uuid_shop_cart = String(uuid);
			try {
				localStorage.setItem("md_uuid_shop_cart", String(uuid));
			} catch { }
		}

		this.state.bookingOnline = res;
		return res;
	}

	_buildBookingOnlinePayload(step, returnUrl) {
		const boatId = this._toInt(this.cfg.boatId, 0);
		const b = this.state.boat_data || {};
		const groupId = this._toInt(b.id_group, 0);

		const sched = this._getPayloadScheduleFields();

		const out = {
			step: step,
			language: String(this.cfg.lang || this.globalCfg.lang || this.globalCfg.default_language || "").trim(),
			return_url_after_payment: String(returnUrl || ""),
			payment_method: String(this.state.form.payment_method || ""),
			uuid_shop_cart: String(this.state.uuid_shop_cart || ""),
			id_group: String(groupId || ""),
			id_group_item: String(boatId || ""),
			date_start: String(this.state.form.date_start || "").trim(),
			date_end: String(this.state.form.date_end || "").trim(),
			time_start: String(sched.time_start || ""),
			time_end: String(sched.time_end || ""),
			id_time_slot: sched.id_time_slot == null ? null : sched.id_time_slot,
			pax: String(this._toInt(this.state.form.people || 1, 1) || 1),
			pax_children: String(this.state.form.children_included ? this._getClampedChildrenCount() : 0),
			message: String(this.state.form.message || "").trim(),
			selected_id_additional_services: this._getSelectedAdditionalsIds(),
		};

		if (step === 1) {
			return out;
		}

		if (step === 2) {
			out.first_name = String(this.state.form.first_name || "").trim();
			out.last_name = String(this.state.form.last_name || "").trim();
			out.email = String(this.state.form.email || "").trim();
			out.phone = String(this.state.form.phone || "").trim();
			out.country_code = String(this.state.form.country || "").trim();
			return out;
		}

		if (step === 3) {
			out.accept_terms = this.state.form.accept_terms ? 1 : 0;
			out.first_name = String(this.state.form.first_name || "").trim();
			out.last_name = String(this.state.form.last_name || "").trim();
			out.email = String(this.state.form.email || "").trim();
			out.phone = String(this.state.form.phone || "").trim();
			out.country_code = String(this.state.form.country || "").trim();

			const totals = this._getPayloadTotalsFromQuote();
			out.price = totals.price;
			out.vat_percent = totals.vat_percent;
			out.vat_price = totals.vat_price;
			out.total_price = totals.total_price;
			return out;
		}

		return out;
	}

	_bindFlatpickrStep1() {
		if (typeof window.flatpickr !== "function") return;

		const inputRange = this.stepContainer?.querySelector('input[data-md-range="date_range_booking"]');
		if (!inputRange) return;

		if (this._fpStep1) {
			try {
				this._fpStep1.destroy();
			} catch { }
			this._fpStep1 = null;
		}

		const minDate = this._computeTodayYmdMadrid() || "today";
		let ds = String(this.state.form.date_start || "").trim();
		let de = String(this.state.form.date_end || "").trim();

		if (this._isYmdBefore(ds, minDate)) {
			ds = "";
			this.state.form.date_start = "";
		}

		if (this._isYmdBefore(de, minDate)) {
			de = "";
			this.state.form.date_end = "";
		}

		const isInlineCalendar = this.cfg.calendarDisplay === "inline";
		const isSingleMode = this.cfg.calendarSelectionMode === "single";
		const calendarContainer = isInlineCalendar
			? this.stepContainer?.querySelector('[data-md-calendar-inline="1"]')
			: null;

		const mode = isSingleMode ? "single" : "range";
		const showMonths = isInlineCalendar
			? 1
			: (this._toInt(this.cfg.calendarMonths, 2) === 1 ? 1 : 2);

		let defaultDate;
		if (isSingleMode) {
			defaultDate = ds || undefined;
		} else if (ds && de) {
			defaultDate = [ds, de];
		} else {
			defaultDate = undefined;
		}

		const syncInputValue = () => {
			const start = String(this.state.form.date_start || "").trim();
			const end = String(this.state.form.date_end || "").trim();

			if (!start && !end) {
				inputRange.value = "";
			} else if (start && end) {
				inputRange.value = start === end ? start : `${start} to ${end}`;
			} else {
				inputRange.value = start || end;
			}
		};

		const disabledDateSet = new Set(this._getFlatpickrDisabledDates());

		syncInputValue();

		const enhanceDayElement = (dayElem) => {
			if (!dayElem || !(dayElem.dateObj instanceof Date)) return;

			const ymd = this._toYmd(dayElem.dateObj);
			const isOutsideMonth = dayElem.classList.contains("prevMonthDay") || dayElem.classList.contains("nextMonthDay");
			const isPast = this._isYmdBefore(ymd, minDate);
			const isUnavailable = disabledDateSet.has(ymd) || dayElem.classList.contains("flatpickr-disabled");

			dayElem.classList.toggle("md-cal-day--outside-month", isOutsideMonth);
			dayElem.classList.toggle("md-cal-day--past", isPast);
			dayElem.classList.toggle("md-cal-day--unavailable", isUnavailable);
			dayElem.classList.toggle("md-cal-day--available", !isOutsideMonth && !isUnavailable);

			if (isPast) {
				dayElem.setAttribute("title", this._t("ui.datePast", "Past date"));
			} else if (isUnavailable) {
				dayElem.setAttribute("title", this._t("ui.dateUnavailable", "Not available"));
			} else if (!isOutsideMonth) {
				dayElem.setAttribute("title", this._t("ui.dateAvailable", "Available"));
			}
		};

		const refreshInlineCalendarUi = (instance) => {
			if (!isInlineCalendar || !instance?.calendarContainer) return;

			instance.calendarContainer.classList.add("md-flatpickr-inline-ready");

			const wrapper = instance.calendarContainer.closest(".md-calendar-inline-wrap");
			if (wrapper) {
				wrapper.classList.add("md-calendar-inline-wrap--ready");
			}

			this._updateFlatpickrHeaderText(instance);
		};

		const flatpickrConfig = {
			mode,
			dateFormat: "Y-m-d",
			minDate,
			defaultDate,
			showMonths,
			closeOnSelect: !isInlineCalendar && isSingleMode,
			locale: this._getFlatpickrLocale(),
			inline: isInlineCalendar,
			static: isInlineCalendar ? true : false,
			appendTo: isInlineCalendar ? undefined : document.body,
			positionElement: isInlineCalendar ? undefined : inputRange,
			monthSelectorType: isInlineCalendar ? "static" : "dropdown",
			disable: Array.from(disabledDateSet),

			onDayCreate: (dObj, dStr, instance, dayElem) => {
				enhanceDayElement(dayElem);
			},

			onReady: (selectedDates, dateStr, instance) => {
				syncInputValue();
				refreshInlineCalendarUi(instance);
			},

			onMonthChange: (selectedDates, dateStr, instance) => {
				refreshInlineCalendarUi(instance);
			},

			onYearChange: (selectedDates, dateStr, instance) => {
				refreshInlineCalendarUi(instance);
			},

			onOpen: (selectedDates, dateStr, instance) => {
				if (isInlineCalendar) {
					refreshInlineCalendarUi(instance);
					return;
				}

				const hasStart = !!ds;
				const hasEnd = !!de;
				const hasAny = hasStart || hasEnd;
				const hasCompleteRange = hasStart && hasEnd;

				if (!hasAny) {
					try {
						this._fpStep1.clear();
					} catch { }
					return;
				}

				if (!isSingleMode && hasStart && !hasEnd) {
					try {
						this._fpStep1.clear();
						this._fpStep1.jumpToDate(ds, true);
						inputRange.value = ds;
					} catch { }
					return;
				}

				if (hasCompleteRange || (isSingleMode && hasStart)) {
					try {
						this._fpStep1.jumpToDate(ds, true);
					} catch { }
				}
			},

			onChange: (selectedDates, dateStr, instance) => {
				if (!Array.isArray(selectedDates) || selectedDates.length === 0) {
					this.state.form.date_start = "";
					this.state.form.date_end = "";
					syncInputValue();
					refreshInlineCalendarUi(instance);
					return;
				}

				if (isSingleMode) {
					const selected = this._toYmd(selectedDates[0]);
					this.state.form.date_start = selected;
					this.state.form.date_end = selected;

					this.state.form.full_day = false;
					this.state.form.timeslot_id = null;
					this.state.form.timeslot = null;

					syncInputValue();
					this.schedulePriceOnBooking();
					refreshInlineCalendarUi(instance);

					if (!isInlineCalendar) {
						try {
							this._fpStep1.close();
						} catch { }
					}
					return;
				}

				if (selectedDates.length === 1) {
					const start = this._toYmd(selectedDates[0]);
					this.state.form.date_start = start;
					this.state.form.date_end = "";
					syncInputValue();
					refreshInlineCalendarUi(instance);
					return;
				}

				const start = this._toYmd(selectedDates[0]);
				const end = this._toYmd(selectedDates[1]);

				this.state.form.date_start = start;
				this.state.form.date_end = end;

				if (start !== end) {
					this.state.form.full_day = true;
					this.state.form.timeslot_id = null;
					this.state.form.timeslot = null;
				}

				syncInputValue();
				this.schedulePriceOnBooking();
				refreshInlineCalendarUi(instance);

				if (!isInlineCalendar) {
					try {
						this._fpStep1.close();
					} catch { }
				}
			},

			onClose: (selectedDates, dateStr, instance) => {
				if (isInlineCalendar) {
					refreshInlineCalendarUi(instance);
					return;
				}

				if (isSingleMode) {
					return;
				}

				const sel = Array.isArray(this._fpStep1?.selectedDates) ? this._fpStep1.selectedDates : [];
				if (sel.length === 1) {
					const s = this._toYmd(sel[0]);
					this.state.form.date_start = s;
					this.state.form.date_end = s;
					syncInputValue();
					this.schedulePriceOnBooking();
				}
			},
		};

		if (isInlineCalendar && calendarContainer) {
			this._fpStep1 = window.flatpickr(calendarContainer, flatpickrConfig);
		} else {
			this._fpStep1 = window.flatpickr(inputRange, flatpickrConfig);
		}

		if (isInlineCalendar && this._fpStep1) {
			refreshInlineCalendarUi(this._fpStep1);
		}
	}

	_getFlatpickrLocale() {
		const cfgLang = String(this.cfg?.lang || "").trim().toLowerCase();
		const htmlLang = String(document.documentElement.getAttribute("lang") || "").trim().toLowerCase();
		const base = (cfgLang || htmlLang || "en").split(/[_-]/)[0];

		const l10ns = window.flatpickr && window.flatpickr.l10ns ? window.flatpickr.l10ns : null;
		if (l10ns && l10ns[base]) return l10ns[base];
		return l10ns && l10ns.default ? l10ns.default : "default";
	}

	_getFlatpickrMonthName(dateObj) {
		if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) {
			return "";
		}

		const locale = this._getUiLocale();

		try {
			const monthName = new Intl.DateTimeFormat(locale, {
				month: "long",
			}).format(dateObj);

			return monthName.charAt(0).toUpperCase() + monthName.slice(1);
		} catch {
			const fallbackMonths = [
				"January", "February", "March", "April", "May", "June",
				"July", "August", "September", "October", "November", "December"
			];

			return fallbackMonths[dateObj.getMonth()] || "";
		}
	}

	_updateFlatpickrHeaderText(instance = null) {
		const fp = instance || this._fpStep1;
		if (!fp || !fp.calendarContainer) return;

		const isInlineCalendar = this.cfg.calendarDisplay === "inline";
		if (!isInlineCalendar) return;

		const monthNodes = fp.calendarContainer.querySelectorAll(".flatpickr-month");
		if (!monthNodes || !monthNodes.length) return;

		monthNodes.forEach((monthNode, index) => {
			const currentMonthEl = monthNode.querySelector(".flatpickr-current-month");
			if (!currentMonthEl) return;

			const nativeMonthEl = currentMonthEl.querySelector(".cur-month");
			if (nativeMonthEl) {
				nativeMonthEl.style.display = "none";
				nativeMonthEl.setAttribute("aria-hidden", "true");
			}

			const nativeDropdownEl = currentMonthEl.querySelector(".flatpickr-monthDropdown-months");
			if (nativeDropdownEl) {
				nativeDropdownEl.style.display = "none";
				nativeDropdownEl.setAttribute("aria-hidden", "true");
				nativeDropdownEl.tabIndex = -1;
			}

			const numInputWrapper = currentMonthEl.querySelector(".numInputWrapper");
			if (numInputWrapper) {
				numInputWrapper.classList.add("md-flatpickr-hidden-year-wrapper");
				numInputWrapper.setAttribute("aria-hidden", "true");
			}

			const yearInput = currentMonthEl.querySelector("input.cur-year");
			if (yearInput) {
				yearInput.classList.add("md-flatpickr-hidden-year-input");
				yearInput.setAttribute("aria-hidden", "true");
				yearInput.tabIndex = -1;
			}

			const monthDate = new Date(fp.currentYear, fp.currentMonth + index, 1);
			if (Number.isNaN(monthDate.getTime())) return;

			const monthText = this._getFlatpickrMonthName(monthDate);
			const yearText = String(monthDate.getFullYear());

			let customLabel = currentMonthEl.querySelector(".md-flatpickr-current-date");

			if (!customLabel) {
				customLabel = document.createElement("span");
				customLabel.className = "md-flatpickr-current-date";
				customLabel.setAttribute("aria-hidden", "true");
				currentMonthEl.appendChild(customLabel);
			}

			customLabel.innerHTML = `
      <span class="md-flatpickr-current-date-month">${this._esc(monthText)}</span>
      <span class="md-flatpickr-current-date-year">${this._esc(yearText)}</span>
    `;
		});
	}

	schedulePriceOnBooking() {
		if (this.state.currentStep !== 1) return;

		const ds = String(this.state.form.date_start || "").trim();
		const de = String(this.state.form.date_end || "").trim();
		const boatIdInt = this._toInt(this.cfg.boatId, 0);

		if (!ds || !de || !boatIdInt) return;

		const isMultiDay = ds !== de;

		if (isMultiDay) {
			this.state.form.full_day = true;
			this.state.form.timeslot_id = null;
			this.state.form.timeslot = null;
		}

		const selectedAdd = this._getSelectedAdditionalsIds().join(",");
		const fullDayKey = this.state.form.full_day ? "1" : "0";
		const tsKey = this.state.form.full_day ? "" : String(this.state.form.timeslot_id || "");
		const key = this._makePriceKey(boatIdInt, ds, de, selectedAdd + "|" + fullDayKey + "|" + tsKey);

		if (key === this.lastPriceKeyDone) return;
		if (key === this.lastPriceKeyQueued) return;

		this.lastPriceKeyQueued = key;

		this._setExtrasBusy(true);

		if (!this.state.quote || typeof this.state.quote !== "object") this.state.quote = {};
		this.state.quote.is_calculating = true;

		if (this.priceTimer) clearTimeout(this.priceTimer);
		this.priceTimer = setTimeout(() => {
			this._runPriceOnBooking(key, boatIdInt, ds, de);
		}, 250);
	}

	async _runPriceOnBooking(key, boatIdInt, ds, de) {
		if (!this.cfg.priceOnBookingEndpoint) return;

		if (this.priceAbort) {
			try {
				this.priceAbort.abort();
			} catch { }
		}

		this.priceAbort = new AbortController();

		try {
			const payload = this._buildPriceOnBookingPayload(boatIdInt, ds, de);
			const data = await this.api.postJson(this.cfg.priceOnBookingEndpoint, payload, { signal: this.priceAbort.signal });
			const hasBookableSelection = this._toInt(payload?.id_time_slot ?? 0, 0) > 0 || this.state.form.full_day === true;

			if (hasBookableSelection && this._isQuoteUnavailable(data)) {
				this.lastPriceKeyDone = key;
				this._setExtrasBusy(false);
				await this._handleUnavailableSelection(data);
				return;
			}

			this.state.quote = data;
			this._lastQuoteOk = data;
			this.lastPriceKeyDone = key;

			this._syncPaymentDefaultsFromQuote(data);

			this._setExtrasBusy(false);

			if (this.state.currentStep === 1) {
				this.handleBoatPriceOnBooking();
			}
		} catch (e) {
			if (e && e.name === "AbortError") return;

			this._setExtrasBusy(false);

			this._showErrorAndScroll(e?.message || this._t("errors.priceOnBookingError", "Price calculation error"));
		}
	}

	_buildPriceOnBookingPayload(boatIdInt, ds, de) {
		const selectedIds = this._getSelectedAdditionalsIds();

		const fullDay = this.state.form.full_day === true;
		const idTimeSlot = !fullDay ? this._toInt(this.state.form.timeslot_id ?? 0, 0) : 0;

		return {
			boat_id: String(boatIdInt),
			from_date: ds,
			to_date: de,
			...(idTimeSlot ? { id_time_slot: idTimeSlot } : {}),
			...(typeof this.state.form.customer_is_skipper === "boolean" ? { customer_is_skipper: this.state.form.customer_is_skipper } : {}),
			...(typeof this.state.form.get_data_gi === "boolean" ? { get_data_gi: this.state.form.get_data_gi } : {}),
			selected_id_additional_services: selectedIds,
		};
	}

	_isQuoteUnavailable(rawQuote) {
		const payload =
			rawQuote && rawQuote.status === "success" && rawQuote.data && typeof rawQuote.data === "object"
				? rawQuote.data
				: rawQuote && rawQuote.success === true && rawQuote.data && typeof rawQuote.data === "object"
					? rawQuote.data
					: rawQuote && rawQuote.data && typeof rawQuote.data === "object"
						? rawQuote.data
						: rawQuote;

		return payload?.availability?.is_available === false;
	}

	async _handleUnavailableSelection(unavailableQuote = null) {
		this.state.form.full_day = false;
		this.state.form.timeslot_id = null;
		this.state.form.timeslot = null;
		this.state.quote = unavailableQuote;
		this._lastQuoteOk = null;

		const boatId = String(this.cfg?.boatId || "").trim();
		if (boatId) {
			delete this._boatCache[boatId];
		}

		await this._hydrateBoatOnOpen({ forceRefresh: true }).catch(() => { });

		if (this.state.currentStep === 1) {
			this.render();
		}

		this._showErrorAndScroll(
			this._t(
				"errors.selectedTimeslotUnavailable",
				"The selected schedule is no longer available. We refreshed the available schedules."
			)
		);

		this.schedulePriceOnBooking();
	}

	_makePriceKey(boatId, ds, de, selectedAddCsv) {
		return String(boatId) + "|" + String(ds) + "|" + String(de) + "|" + String(selectedAddCsv || "");
	}

	_setExtrasBusy(isBusy) {
		if (this.state.currentStep !== 1) return;

		const boxes = this.stepContainer?.querySelectorAll('input[data-md-additional-checkbox="1"]') || [];
		boxes.forEach((el) => {
			el.disabled = !!isBusy;
			el.closest?.("label")?.classList.toggle("is-busy", !!isBusy);
		});

		if (this.nextBtn) this.nextBtn.disabled = !!isBusy;

		const skipperSel = this.stepContainer?.querySelector('select[data-md-field="hire_skipper"]');
		if (skipperSel) skipperSel.disabled = !!isBusy;
	}

	_setOpenButtonReady(isReady) {
		if (!this.openBtn) return;

		this.openBtn.disabled = !isReady;
		this.openBtn.setAttribute("aria-disabled", isReady ? "false" : "true");
		this.openBtn.setAttribute("data-md-open-ready", isReady ? "1" : "0");
		this.openBtn.classList.toggle("is-loading", !isReady);

		if (isReady) {
			this.openBtn.setAttribute("aria-busy", "false");
		} else {
			this.openBtn.setAttribute("aria-busy", "true");
		}
	}

	_setOpenButtonBusy(isBusy) {
		if (!this.openBtn) return;

		this.openBtn.disabled = !!isBusy;
		this.openBtn.classList.toggle("is-loading", !!isBusy);
		this.openBtn.setAttribute("aria-busy", isBusy ? "true" : "false");
	}

	handleBoatPriceOnBooking() {
		if (this.state.currentStep !== 1) return;

		const q = this._extractSidebarQuoteData();

		const extrasWrap = this.stepContainer?.querySelector('[data-md-extras-live="1"]');
		if (extrasWrap) extrasWrap.innerHTML = this._renderExtrasBlock(q);

		const sideCard = this.stepContainer?.querySelector("#bookingSideBarCard");
		if (sideCard) sideCard.outerHTML = this._renderBookingSidebarCard();

		const tsWrap = this.stepContainer?.querySelector('[data-md-timeslot-wrap="1"]');
		if (tsWrap) tsWrap.innerHTML = this._renderTimeslotSelectorIfNeeded();

		this._renderOrUpdateSkipperPrompt();
	}

	async _getRentalTerms(group = "2") {
		const baseUrl = String(this.cfg?.rentalTermsEndpoint || "").trim();
		if (!baseUrl) {
			throw new Error(this._t("errors.rentalTermsEndpointMissing", "Rental terms endpoint not configured."));
		}

		const language = String(this.cfg?.lang || "EN").trim().toUpperCase();
		const url = `${baseUrl}/${encodeURIComponent(group)}?language=${encodeURIComponent(language)}&decoded_html=true`;

		const res = await this.api.getJson(url, {
			nonce: this.cfg?.restNonce || ""
		});

		const ok =
			(res && res.status === "success") ||
			(res && res.success === true);

		if (!ok) {
			throw new Error(
				res?.message ||
				res?.error?.message ||
				this._t("errors.rentalTermsError", "Could not load rental terms.")
			);
		}

		const payload =
			res?.data && typeof res.data === "object"
				? res.data
				: res;

		return String(payload?.rental_terms || "").trim();
	}

	_getPayloadScheduleFields() {
		const q = this.state?.quote || null;

		const payload =
			q && q.status === "success" && q.data && typeof q.data === "object"
				? q.data
				: q && q.success === true && q.data && typeof q.data === "object"
					? q.data
					: q && q.data && typeof q.data === "object"
						? q.data
						: q;

		const out = {
			time_start: "",
			time_end: "",
			id_time_slot: null,
		};

		const ts = this.state?.form?.timeslot || null;
		const tsId =
			this.state?.form?.timeslot_id != null && String(this.state.form.timeslot_id).trim() !== ""
				? this._toInt(this.state.form.timeslot_id, 0)
				: 0;

		const isHalfDayWithTimeslot = !!(ts && ts.start_time && ts.duration && tsId > 0 && this.state?.form?.full_day !== true);

		if (isHalfDayWithTimeslot) {
			const range = this._formatTimeslotRange(ts.start_time, ts.duration, { useAmPm: false });
			if (range && range.includes("-")) {
				const parts = range.split("-").map((s) => String(s || "").trim());
				out.time_start = parts[0] || "";
				out.time_end = parts[1] || "";
			}
			out.id_time_slot = tsId;
			return out;
		}

		const checkin = payload?.boat?.schedules?.checkin || "";
		const checkout = payload?.boat?.schedules?.checkout || "";

		if (typeof checkin === "string" && checkin) out.time_start = checkin;
		if (typeof checkout === "string" && checkout) out.time_end = checkout;

		out.id_time_slot = null;

		return out;
	}

	_getPayloadTotalsFromQuote() {
		const q = this.state?.quote || null;

		const payload =
			q && q.status === "success" && q.data && typeof q.data === "object"
				? q.data
				: q && q.success === true && q.data && typeof q.data === "object"
					? q.data
					: q && q.data && typeof q.data === "object"
						? q.data
						: q;

		const pickNum = (v) => {
			const n = this._toFloat(v, null);
			return n == null ? null : n;
		};

		const pickPositiveNum = (...values) => {
			for (const value of values) {
				const n = pickNum(value);
				if (n != null && n > 0) {
					return n;
				}
			}
			return null;
		};

		const firstPositiveFromArray = (arr, getter) => {
			if (!Array.isArray(arr)) return null;

			for (const item of arr) {
				const n = pickNum(getter(item));
				if (n != null && n > 0) {
					return n;
				}
			}

			return null;
		};

		const price =
			pickNum(this._pick(payload, "amounts.grand_total.base")) ??
			pickNum(this._pick(payload, "amounts.grand_total.raw.base")) ??
			pickNum(this._pick(payload, "amounts.service.base")) ??
			pickNum(this._pick(payload, "amounts.service.raw.base")) ??
			pickNum(this._pick(payload, "amounts.service.raw.subtotal")) ??
			pickNum(this._pick(payload, "price")) ??
			null;

		const vat_percent =
			pickPositiveNum(
				this._pick(payload, "amounts.grand_total.vat_percent"),
				this._pick(payload, "amounts.grand_total.raw.vat_percent"),
				this._pick(payload, "amounts.service.vat_percent"),
				this._pick(payload, "amounts.service.raw.vat_percent"),
				this._pick(payload, "vat_percent"),
				this._pick(payload, "amounts.vat_percent")
			) ??
			firstPositiveFromArray(
				this._pick(payload, "boat.price_ranges"),
				(item) => item?.vat_percent
			) ??
			firstPositiveFromArray(
				this._pick(payload, "additionals"),
				(item) => item?.amounts?.vat_percent
			) ??
			firstPositiveFromArray(
				this._pick(payload, "additionals_available"),
				(item) => item?.amounts?.vat_percent
			) ??
			firstPositiveFromArray(
				this._pick(payload, "lines"),
				(item) => item?.amounts?.vat_percent
			) ??
			null;

		const vat_price =
			pickNum(this._pick(payload, "amounts.grand_total.vat")) ??
			pickNum(this._pick(payload, "amounts.grand_total.raw.vat")) ??
			pickNum(this._pick(payload, "amounts.service.vat")) ??
			pickNum(this._pick(payload, "amounts.service.raw.vat")) ??
			pickNum(this._pick(payload, "vat_price")) ??
			null;

		const total_price =
			pickNum(this._pick(payload, "amounts.grand_total.total")) ??
			pickNum(this._pick(payload, "amounts.grand_total.raw.total")) ??
			pickNum(this._pick(payload, "amounts.grand_total.raw.grand_total")) ??
			pickNum(this._pick(payload, "amounts.service.total")) ??
			pickNum(this._pick(payload, "amounts.service.raw.total")) ??
			pickNum(this._pick(payload, "total_price")) ??
			null;

		const payment_commission_percent =
			pickNum(this._pick(payload, "payment.card_commission.percent")) ??
			pickNum(this._pick(payload, "prepayment.due_now.commission.percent")) ??
			null;

		const payment_commission_base =
			pickNum(this._pick(payload, "prepayment.due_now.commission.base")) ??
			null;

		const payment_commission_vat =
			pickNum(this._pick(payload, "prepayment.due_now.commission.vat")) ??
			null;

		const payment_commission_total =
			pickNum(this._pick(payload, "prepayment.due_now.commission.total")) ??
			null;

		return {
			price,
			vat_percent,
			vat_price,
			total_price,
			payment_commission_percent,
			payment_commission_base,
			payment_commission_vat,
			payment_commission_total,
		};
	}

	_renderOrUpdateSkipperPrompt() {
		if (this.state.currentStep !== 1) return;

		const slot = this.stepContainer?.querySelector('[data-md-skipper-slot="1"]');
		if (!slot) return;

		const skipperOpt = this._getOptionalSkipperOnGiInfo();

		if (!skipperOpt?.idOnGi) {
			slot.innerHTML = "";
			return;
		}

		const isChecked = !!this.state.form.additionals_selected?.[skipperOpt.idOnGi];
		const value = isChecked ? "yes" : "no";
		this.state.form.hire_skipper = value;

		slot.innerHTML = `
      <div class="md-mt-8 md-skipper-prompt">
        <label class="md-field__label">${this._t("labels.hireSkipperTitle", "Would you like a professional skipper on board?")}</label>
        <div class="md-muted">
          ${this._t(
			"labels.hireSkipperHelp",
			"Recommended for a stress-free trip, especially if you’re not fully familiar with the area or docking."
		)}
        </div>

        <select data-md-field="hire_skipper">
          <option value="yes" ${value === "yes" ? "selected" : ""}>${this._t("ui.yesRecommended", "Yes (recommended)")}</option>
          <option value="no" ${value === "no" ? "selected" : ""}>${this._t("ui.no", "No")}</option>
        </select>
      </div>
    `;
	}

	async _bindStep2CountryAndPhone() {
		if (this.state.currentStep !== 2) return;
		const countrySelect = this.stepContainer?.querySelector('select[data-md-country-select="1"]');
		const phoneInput = this.stepContainer?.querySelector('input[data-md-phone-input="1"]');
		if (!countrySelect || !phoneInput) return;

		this._step2CountryEl = countrySelect;
		this._step2PhoneEl = phoneInput;

		if (!countrySelect.__mdCountriesFilled) {
			countrySelect.innerHTML = `<option value="">${this._t("ui.selectCountry", "Select country")}</option>`;
			countrySelect.disabled = true;

			try {
				const countries = await this._getCountries(this.cfg?.lang || "EN");

				const current = String(this.state.form.country || "").toUpperCase();
				const defIso2 = current || "ES";

				countrySelect.innerHTML =
					`<option value="">${this._t("ui.selectCountry", "Select country")}</option>` +
					countries
						.map((c) => {
							const sel = c.iso2 === defIso2 ? "selected" : "";
							return `<option value="${this._esc(c.iso2)}" data-md-dial="${this._esc(c.calling_code)}" ${sel}>${this._esc(
								c.name
							)}</option>`;
						})
						.join("");

				countrySelect.__mdCountriesFilled = true;

				if (!this.state.form.country) {
					this.state.form.country = defIso2;
					countrySelect.value = defIso2;
				}
			} catch (e) {
				if (!this.state.form.country) {
					this.state.form.country = "ES";
					countrySelect.value = "ES";
				}
			} finally {
				countrySelect.disabled = false;
			}
		}

		this._initIntlTelInput(phoneInput, String(this.state.form.country || "ES"));

		if (!countrySelect.__mdCountryBound) {
			countrySelect.addEventListener("change", () => {
				const iso2 = String(countrySelect.value || "").toUpperCase();
				this.state.form.country = iso2;

				if (this._iti && iso2) {
					try {
						this._iti.setCountry(iso2.toLowerCase());
					} catch { }
				}

				this._syncPhoneFromIti();
			});
			countrySelect.__mdCountryBound = true;
		}

		if (!phoneInput.__mdPhoneBound) {
			phoneInput.addEventListener("input", () => this._syncPhoneFromIti());
			phoneInput.addEventListener("blur", () => this._syncPhoneFromIti());
			phoneInput.__mdPhoneBound = true;
		}

		this._syncPhoneFromIti();
	}

	_destroyIntlTelInputAndCountry() {
		if (this._iti) {
			try {
				this._iti.destroy();
			} catch { }
			this._iti = null;
		}
		this._step2PhoneEl = null;
		this._step2CountryEl = null;
	}

	async _getCountries(lang = "") {
		const urlBase = this.cfg?.countriesEndpoint;
		if (!urlBase) return [];

		const L = String(lang || this.cfg?.lang || "EN").toUpperCase();
		const now = Date.now();

		if (
			Array.isArray(this._countriesCache) &&
			this._countriesCache.length &&
			this._countriesCacheLang === L &&
			now - this._countriesCacheTs < this._countriesCacheTtlMs
		) {
			return this._countriesCache;
		}

		const url = urlBase + (urlBase.includes("?") ? "&" : "?") + "lang=" + encodeURIComponent(L);

		const res = await this.api.getJson(url, { nonce: this.cfg?.restNonce || "" });

		const list = Array.isArray(res?.data) ? res.data : Array.isArray(res?.data?.data) ? res.data.data : [];

		const out = list
			.filter((x) => x && typeof x === "object")
			.map((x) => ({
				iso2: String(x.iso2 || "").toUpperCase().trim(),
				name: String(x.name || "").trim(),
				calling_code: String(x.calling_code || "").trim(),
			}))
			.filter((x) => x.iso2.length === 2 && x.name !== "");

		this._countriesCache = out;
		this._countriesCacheLang = L;
		this._countriesCacheTs = now;

		return out;
	}

	_initIntlTelInput(inputEl, countryIso2 = "ES") {
		if (!window.intlTelInput || typeof window.intlTelInput !== "function") return;

		if (this._iti && this._step2PhoneEl === inputEl) return;

		if (this._iti) {
			try {
				this._iti.destroy();
			} catch { }
			this._iti = null;
		}

		const initialCountry = String(countryIso2 || "ES").toLowerCase();

		this._iti = window.intlTelInput(inputEl, {
			initialCountry,
			separateDialCode: true,
			nationalMode: false,
			autoPlaceholder: "polite",
			preferredCountries: ["es", "gb", "fr", "it", "de", "pt"],
			utilsScript: this.globalCfg?.intlTelUtilsUrl || "",
			dropdownContainer: this.modal,
			formatOnDisplay: true,
		});
	}

	_syncPhoneFromIti() {
		const inputEl = this._step2PhoneEl;
		if (!inputEl) return;

		this.state.form.phone_raw = String(inputEl.value || "").trim();

		if (!this._iti) {
			this.state.form.phone = this.state.form.phone_raw;
			this._setPhoneHint(false);
			return;
		}

		const utilsReady = !!window.intlTelInputUtils;

		let e164 = "";
		if (utilsReady) {
			try {
				e164 = this._iti.getNumber();
			} catch {
				e164 = "";
			}
		}

		if (!e164) {
			const dialEl = this.wrapper?.querySelector(".iti__selected-dial-code");
			const dial = dialEl ? String(dialEl.textContent || "").trim() : "";
			const rawDigits = this.state.form.phone_raw.replace(/[^\d]/g, "");

			if (this.state.form.phone_raw.startsWith("+")) {
				e164 = this.state.form.phone_raw;
			} else if (dial && rawDigits) {
				e164 = dial + rawDigits;
			} else {
				e164 = this.state.form.phone_raw;
			}
		}

		this.state.form.phone = String(e164 || "").trim();

		if (!utilsReady) {
			this._setPhoneHint(false);
			return;
		}

		let show = false;
		try {
			const isValid = this._iti.isValidNumber();
			if (!isValid && this.state.form.phone_raw !== "") show = true;
		} catch { }

		this._setPhoneHint(show);
	}

	_setPhoneHint(show) {
		const hint = this.stepContainer?.querySelector('[data-md-phone-hint="1"]');
		if (!hint) return;
		if (show) {
			hint.textContent = this._t("errors.invalidPhone", "Invalid phone number");
			hint.style.display = "";
		} else {
			hint.textContent = "";
			hint.style.display = "none";
		}
	}


	_submitPaymentForm(actionUrl, fields) {
		const form = document.createElement("form");
		form.method = "POST";
		form.action = actionUrl;
		form.style.display = "none";

		Object.entries(fields || {}).forEach(([key, value]) => {
			const input = document.createElement("input");
			input.type = "hidden";
			input.name = String(key);
			input.value = value === null || typeof value === "undefined" ? "" : String(value);
			form.appendChild(input);
		});

		document.body.appendChild(form);
		form.submit();
	}

	async _startOnlinePaymentFlow() {
		console.group("[Payment] _startOnlinePaymentFlow()");

		this._hide(this.errBox);
		this._hide(this.infoBox);

		if (!this.cfg.bookingOnlineEndpoint) {
			console.error("Missing bookingOnlineEndpoint");
			this._showErrorAndScroll(this._t("errors.bookingEndpointMissing", "Booking endpoint not configured."));
			console.groupEnd();
			return;
		}

		this._setNavigationBusy(true);

		try {
			const returnUrl = this._buildReturnUrlAfterPayment();
			const payload = this._buildBookingOnlinePayload(3, returnUrl);


			const res = await this.api.postJson(
				this.cfg.bookingOnlineEndpoint,
				payload,
				{ timeoutMs: this.bookingTimeoutMs }
			);


			const ok =
				(res && res.success === true) ||
				(res && res.status === "success") ||
				(res && res.result && res.result.success === true) ||
				(res && res.result && res.result.status === "success");


			if (!ok) {
				const msg =
					res?.message ||
					res?.error ||
					res?.result?.message ||
					res?.result?.error ||
					this._t("errors.bookingError", "Booking error");

				console.error("payment flow invalid response:", msg);
				throw new Error(String(msg));
			}

			const uuidShopCart = res?.result?.uuid_shop_cart || res?.uuid_shop_cart || "";
			const buildPayment = res?.result?.build_payment || res?.build_payment || null;


			if (uuidShopCart) {
				this.state.uuid_shop_cart = String(uuidShopCart);
				try {
					localStorage.setItem("md_uuid_shop_cart", String(uuidShopCart));
				} catch (storageError) {
					console.warn("could not store uuid in localStorage:", storageError);
				}
			}

			const actionUrl = typeof buildPayment?.url === "string" ? buildPayment.url : "";

			if (!buildPayment || !actionUrl) {
				console.error("No payment data returned");
				throw new Error(this._t("errors.bookingError", "No payment data returned."));
			}

			this._show(this.infoBox, this._t("ui.redirecting", "Redirecting…"));

			if (buildPayment.form && typeof buildPayment.form === "object") {
				this._submitPaymentForm(actionUrl, buildPayment.form);
				console.groupEnd();
				return;
			}

			window.location.href = actionUrl;
		} catch (e) {
			console.error("[Booking step 3] error:", e);
			this._showBookingRequestError(e);
		} finally {
			this._setNavigationBusy(false);
			console.groupEnd();
		}
	}

	_fetchShopCart(uuid) {
		const cleanUuid = String(uuid || "").trim();

		if (!cleanUuid) {
			throw new Error(this._t("errors.shopCartNotFound", "Shop cart not found."));
		}

		const endpoint = this.api.joinUrl(
			this._wpJsonBase(),
			"maradigma/v1/shop-cart/" + encodeURIComponent(cleanUuid)
		);

		return this.api.getJson(endpoint, {
			nonce: this.cfg?.restNonce || ""
		});
	}

	_restoreStateFromShopCart(res) {
		const payload =
			(res?.status === "success" && res?.data && typeof res.data === "object")
				? res.data
				: (res?.success === true && res?.data && typeof res.data === "object")
					? res.data
					: (res?.result && typeof res.result === "object")
						? res.result
						: res || {};

		const shopCart =
			payload?.shop_cart ||
			payload;

		const cartBooking =
			payload?.cart_booking ||
			shopCart?.cart_booking ||
			{};

		const cartSummary =
			payload?.cart_summary ||
			shopCart?.cart_summary ||
			null;

		const cartAdditionals =
			payload?.cart_additional_services ||
			shopCart?.cart_additional_services ||
			[];

		const bookingForCustomer =
			payload?.booking ||
			null;

		const paymentForCustomer =
			payload?.payment ||
			null;

		const customerForCustomer =
			payload?.customer ||
			null;

		const bookingRestore =
			payload?.booking_restore ||
			null;

		this.state.paymentReturn = {
			booking: bookingForCustomer,
			payment: paymentForCustomer,
			customer: customerForCustomer,
			booking_restore: bookingRestore,
			is_paid: !!(
				payload?.is_paid ??
				payload?.paid ??
				payload?.payment?.paid ??
				false
			),
		};

		const boatId =
			shopCart?.id_group_item ??
			bookingRestore?.id_group_item ??
			this.cfg.boatId ??
			"";

		const groupId =
			shopCart?.id_group ??
			bookingRestore?.id_group ??
			this.state?.boat_data?.id_group ??
			"";

		if (boatId) {
			this.cfg.boatId = String(boatId);
			this.wrapper.setAttribute("data-boat-id", String(boatId));
		}

		this.state.form.date_start = String(
			shopCart?.date_start ??
			bookingRestore?.date_start ??
			""
		).trim();

		this.state.form.date_end = String(
			shopCart?.date_end ??
			bookingRestore?.date_end ??
			""
		).trim();

		this.state.form.full_day = !!(
			shopCart?.id_time_slot == null &&
			this.state.form.date_start &&
			this.state.form.date_end &&
			this.state.form.date_start !== this.state.form.date_end
		);

		this.state.form.timeslot_id =
			shopCart?.id_time_slot ??
			bookingRestore?.id_time_slot ??
			null;

		this.state.form.timeslot = null;

		this.state.form.people = this._toInt(
			cartBooking?.pax ??
			bookingRestore?.pax ??
			1,
			1
		);

		this.state.form.pax_children = this._toInt(
			cartBooking?.pax_children ??
			bookingRestore?.pax_children ??
			0,
			0
		);

		this.state.form.children_included = this.state.form.pax_children > 0;

		this.state.form.message = String(
			shopCart?.message ??
			bookingRestore?.message ??
			""
		).trim();

		this.state.form.first_name = String(
			customerForCustomer?.first_name ??
			shopCart?.first_name ??
			""
		).trim();

		this.state.form.last_name = String(
			customerForCustomer?.last_name ??
			shopCart?.last_name ??
			""
		).trim();

		this.state.form.email = String(
			customerForCustomer?.email ??
			shopCart?.email ??
			""
		).trim();

		this.state.form.phone = String(
			customerForCustomer?.phone ??
			shopCart?.phone ??
			""
		).trim();

		this.state.form.phone_raw = this.state.form.phone;

		this.state.form.country = String(
			customerForCustomer?.country_code ??
			shopCart?.country_code ??
			"ES"
		).toUpperCase().trim();

		this.state.form.payment_method = String(
			shopCart?.payment_method ??
			bookingRestore?.payment_method ??
			""
		).trim();

		this.state.form.accept_terms = false;

		this.state.form.additionals_selected = {};

		if (Array.isArray(cartAdditionals)) {
			cartAdditionals.forEach((additionalRow) => {
				const additionalId =
					this._toInt(additionalRow?.id_additional_on_gi ?? additionalRow?.id ?? 0, 0);

				if (additionalId > 0) {
					this.state.form.additionals_selected[additionalId] = true;
				}
			});
		}

		if (groupId) {
			this.state.boat_data = {
				...(this.state.boat_data || {}),
				id_group: this._toInt(groupId, 0)
			};
		}

		if (cartSummary && typeof cartSummary === "object") {
			this.state.quote = cartSummary;
			this._lastQuoteOk = cartSummary;
			this._syncPaymentDefaultsFromQuote(cartSummary);
		}
	}

	async _finalizeSuccessfulPaymentReturn(res) {
		const payload =
			(res?.status === "success" && res?.data && typeof res.data === "object")
				? res.data
				: (res?.success === true && res?.data && typeof res.data === "object")
					? res.data
					: (res?.result && typeof res.result === "object")
						? res.result
						: res || {};

		const paid = !!(
			payload?.paid ??
			payload?.is_paid ??
			payload?.payment?.paid ??
			false
		);

		if (paid !== true) {
			this._restoreStateFromShopCart(res);

			await this._hydrateBoatOnOpen().catch(() => { });

			if (
				this.state.form.date_start &&
				this.state.form.date_end &&
				this._toInt(this.cfg.boatId, 0) > 0
			) {
				try {
					const payloadPriceRestore = this._buildPriceOnBookingPayload(
						this._toInt(this.cfg.boatId, 0),
						this.state.form.date_start,
						this.state.form.date_end
					);

					const data = await this.api.postJson(this.cfg.priceOnBookingEndpoint, payloadPriceRestore);

					this.state.quote = data;
					this._lastQuoteOk = data;
					this._syncPaymentDefaultsFromQuote(data);
				} catch { }
			}

			this._openRecoveredModal();

			this.state.currentStep = 3;
			this.render();

			if (this.nextBtn) this.nextBtn.disabled = false;
			if (this.backBtn) this.backBtn.disabled = false;

			this._showErrorAndScroll(
				this._t(
					"ui.paymentNotConfirmed",
					"We could not confirm the payment. Please try again."
				)
			);

			return;
		}

		this._restoreStateFromShopCart(res);

		await this._hydrateBoatOnOpen().catch(() => { });

		if (
			this.state.form.date_start &&
			this.state.form.date_end &&
			this._toInt(this.cfg.boatId, 0) > 0
		) {
			try {
				const payloadPriceRestore = this._buildPriceOnBookingPayload(
					this._toInt(this.cfg.boatId, 0),
					this.state.form.date_start,
					this.state.form.date_end
				);

				const data = await this.api.postJson(this.cfg.priceOnBookingEndpoint, payloadPriceRestore);

				this.state.quote = data;
				this._lastQuoteOk = data;
				this._syncPaymentDefaultsFromQuote(data);
			} catch { }
		}

		this._openRecoveredModal();

		this.state.currentStep = 4;
		this.render();

		if (this.nextBtn) this.nextBtn.disabled = false;
		if (this.backBtn) this.backBtn.disabled = false;
	}

	async _restoreFailedPaymentReturn(res) {
		this._restoreStateFromShopCart(res);

		await this._hydrateBoatOnOpen().catch(() => { });

		if (this.state.form.date_start && this.state.form.date_end && this._toInt(this.cfg.boatId, 0) > 0) {
			try {
				const payload = this._buildPriceOnBookingPayload(
					this._toInt(this.cfg.boatId, 0),
					this.state.form.date_start,
					this.state.form.date_end
				);

				const data = await this.api.postJson(this.cfg.priceOnBookingEndpoint, payload);

				this.state.quote = data;
				this._lastQuoteOk = data;
				this._syncPaymentDefaultsFromQuote(data);
			} catch { }
		}

		this._openRecoveredModal();

		this.state.currentStep = 3;
		this.render();

		if (this.nextBtn) this.nextBtn.disabled = false;
		if (this.backBtn) this.backBtn.disabled = false;

		this._showErrorAndScroll(
			this._t("ui.paymentCancelled", "Payment was cancelled or failed. You can try again.")
		);
	}

	async _handleReturnFromPayment() {
		const url = new URL(window.location.href);
		const payment = url.searchParams.get("payment");
		const uuid = url.searchParams.get("bchShopCart");

		if (!uuid) {
			return;
		}

		const cleanUuid = String(uuid || "").trim();
		const returnKey = `bchShopCart:${cleanUuid}`;
		const handledReturns = window.__MD_BOOKING_PAYMENT_RETURN_HANDLED__ || {};

		if (handledReturns[returnKey]) {
			return;
		}

		handledReturns[returnKey] = true;
		window.__MD_BOOKING_PAYMENT_RETURN_HANDLED__ = handledReturns;

		try {
			const res = await this._fetchShopCart(cleanUuid);

			const payload =
				(res?.status === "success" && res?.data && typeof res.data === "object")
					? res.data
					: (res?.success === true && res?.data && typeof res.data === "object")
						? res.data
						: (res?.result && typeof res.result === "object")
							? res.result
							: res || {};

			const paid =
				!!(payload?.paid ?? payload?.is_paid ?? payload?.payment?.paid ?? false) ||
				!!(payload?.payment && typeof payload.payment === "object" && payload.payment.reference);

			this.state.uuid_shop_cart = String(cleanUuid);

			try {
				localStorage.setItem("md_uuid_shop_cart", String(cleanUuid));
			} catch { }

			if (paid || payment === "success") {
				await this._finalizeSuccessfulPaymentReturn(res);
			} else if (payment === "error") {
				await this._restoreFailedPaymentReturn(res);
			} else {
				this._restoreStateFromShopCart(res);

				await this._hydrateBoatOnOpen().catch(() => { });

				await this.open();

				this._show(
					this.infoBox,
					this._t("ui.paymentPending", "Payment not completed yet. If you already paid, refresh in a moment.")
				);
			}
		} catch (e) {
			this._showErrorAndScroll(
				e?.message || this._t("errors.bookingError", "Booking error")
			);
		}
	}

	_openRecoveredModal() {
		if (this._isOpening || this._isOpen) {
			return;
		}

		this._hide(this.infoBox);
		this._hide(this.errBox);
		this._clearInlineErrors();

		this.lastPriceKeyQueued = "";
		this.lastPriceKeyDone = "";

		this._openModal();
		this._isOpen = true;
		this._isOpening = false;
		this._setOpenButtonBusy(false);
		this._scrollModalBodyToTop("auto");
	}

	_buildReturnUrlAfterPayment() {
		const u = new URL(String(window.location.href || ""), window.location.origin);
		u.hash = "";
		return u.toString();
	}

	_renderStep1() {
		const capacity = this._toInt(this.state.boat_capacity || "0", 0);
		const peopleVal = this._toInt(this.state.form.people || "1", 1) || 1;
		const maxChildren = this._getMaxChildrenForPeople(peopleVal);
		const childrenVal = this._getClampedChildrenCount(peopleVal);
		if (this._toInt(this.state.form.pax_children || 0, 0) !== childrenVal) {
			this.state.form.pax_children = childrenVal;
		}

		let peopleField = "";
		if (capacity > 0) {
			let options = "";
			for (let i = 1; i <= capacity; i++) {
				options += `<option value="${i}" ${i === peopleVal ? "selected" : ""}>${i}</option>`;
			}
			peopleField = `<select data-md-field="people">${options}</select>`;
		} else {
			peopleField = `<select data-md-field="people"><option value="${peopleVal}">${peopleVal}</option></select>`;
		}

		const q = this._extractSidebarQuoteData();
		const extrasHtml = `<div data-md-extras-live="1">${this._renderExtrasBlock(q)}</div>`;

		const childrenChecked = this.state.form.children_included ? "checked" : "";
		const shouldShowChildrenBlock = this.cfg.showChildrenIncluded === true;
		const showChildrenSelect = shouldShowChildrenBlock && !!this.state.form.children_included;

		let childrenOptions = `<option value="0" ${childrenVal === 0 ? "selected" : ""}>0</option>`;
		for (let i = 1; i <= maxChildren; i++) {
			childrenOptions += `<option value="${i}" ${childrenVal === i ? "selected" : ""}>${i}</option>`;
		}

		const isInlineCalendar = this.cfg.calendarDisplay === "inline";
		const isSingleMode = this.cfg.calendarSelectionMode === "single";

		const ds = String(this.state.form.date_start || "").trim();
		const de = String(this.state.form.date_end || "").trim();

		let dateInputValue = "";
		if (ds && de) {
			dateInputValue = ds === de ? ds : `${ds} to ${de}`;
		} else if (ds) {
			dateInputValue = ds;
		}

		const dateFieldHtml = isInlineCalendar
			? `
        <div class="md-col-12 md-field md-field--calendar-inline">
          <label class="md-field__label">${this._t("labels.dateRange", isSingleMode ? "Date" : "Dates")}</label>

          <div class="md-calendar-inline-wrap">
            <div data-md-calendar-inline="1"></div>
          </div>

          <input
            type="hidden"
            data-md-range="date_range_booking"
            value="${this._esc(dateInputValue)}"
          >
        </div>
      `
			: `
        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.dateRange", isSingleMode ? "Date" : "Dates")}</label>
          <input
            type="text"
            data-md-range="date_range_booking"
            placeholder="${this._t("ui.selectDates", isSingleMode ? "Select date" : "Select dates")}"
            value="${this._esc(dateInputValue)}"
          >
        </div>
      `;

		return `
      <div class="md-booking-summary-layout md-step1-layout">
        <div class="md-booking-summary-main md-step1-main">
          ${this._renderInlineStepTitle(1)}

          <div class="md-grid">
            ${dateFieldHtml}

            <div class="md-col-12" data-md-timeslot-wrap="1"></div>

            <div class="md-col-12 md-field">
              <label class="md-field__label">${this._t("labels.people", "People")}</label>
              ${peopleField}
            </div>

            ${shouldShowChildrenBlock ? `
              <div class="md-col-12 md-field">
                <label class="md-checkline">
                  <input type="checkbox" data-md-field="children_included" ${childrenChecked}>
                  <span class="md-checkline__text">
                    <span class="md-checkline__title">
                      ${this._t("labels.childrenIncluded", "Including children onboard")}
                    </span>
                  </span>
                </label>
              </div>

              <div class="md-col-12 md-field" data-md-children-wrap="1" style="${showChildrenSelect ? "" : "display:none;"}">
                <label class="md-field__label">${this._t("labels.chooseChildren", "Choose the number of children")}</label>
                <select data-md-field="pax_children" name="pax_children">
                  ${childrenOptions}
                </select>
              </div>
            ` : ""}

            <div class="md-col-12 md-field" data-md-skipper-slot="1"></div>

            <div class="md-col-12 md-field">
              <label class="md-field__label">${this._t("labels.optionalMessage", "Optional message")}</label>
              <div class="md-muted md-field__help">
                ${this._t("labels.optionalMessageHelp", "You can specify your project (schedule, program, particular needs)")}
              </div>
              <textarea
                data-md-field="message"
                name="message"
                rows="3"
                placeholder="${this._t("ui.messagePlaceholder", "Write your message (optional)…")}"
              >${this._esc(this.state.form.message || "")}</textarea>
            </div>
          </div>

          ${extrasHtml}
        </div>

        <aside class="md-booking-summary-sidebar md-step1-sidebar">
          ${this._renderBookingSidebarCard()}
        </aside>
      </div>
    `;
	}

	_renderExtrasBlock(q) {
		if (q && q.isCalculating) {
			return `<div class="md-hint__box">${this._t("ui.calculating", "Calculating…")}</div>`;
		}

		const additionalsForUI =
			Array.isArray(q?.additionalsAvailable) && q.additionalsAvailable.length
				? q.additionalsAvailable
				: typeof this._extractBoatAdditionalsForUI === "function"
					? this._extractBoatAdditionalsForUI()
					: [];

		if (!Array.isArray(additionalsForUI) || !additionalsForUI.length) {
			return "";
		}

		const optionalRowsHtml = additionalsForUI
			.filter((a) => this._toInt(a?.optionalType, 0) === 2)
			.map((a) => {
				const idOnGi = this._toInt(a.id, 0);

				if (!idOnGi) {
					return "";
				}

				const name = this._esc(a.name || "—");
				const price = this._esc(a.priceFormatted || this._getFreeAdditionalLabel());
				const checked = this.state.form.additionals_selected?.[idOnGi] ? "checked" : "";

				return `
      <label class="md-extras__item md-extras__item--selectable">
        <div class="md-extras__check">
          <input
            type="checkbox"
            data-md-additional-checkbox="1"
            data-md-id-additional-on-gi="${idOnGi}"
            data-md-id-optional-service="${this._toInt(a.idOptionalService, 0)}"
            ${checked}
          >
        </div>

        <div class="md-extras__item-main">
          <div class="md-extras__item-name">${name}</div>
        </div>

        <div class="md-extras__item-side">
          <div class="md-extras__item-price">${price}</div>
        </div>
      </label>
    `;
			})
			.join("");

		if (!optionalRowsHtml) {
			return "";
		}

		return `
  <div class="md-extras">
    <div class="md-extras__head">
      <div class="md-side__section-title">${this._t("labels.extras", "Extras")}</div>
    </div>

    <div class="md-extras__list">
      ${optionalRowsHtml}
    </div>
  </div>
`;
	}

	_renderBookingSidebarCard() {
		const boat = this._extractSidebarBoatData();
		const quote = this._extractSidebarQuoteData();

		const title = boat.title ? this._esc(boat.title) : "—";
		const port = boat.port ? this._esc(boat.port) : "—";

		const dateText = this._formatDateRangeHuman(this.state.form.date_start, this.state.form.date_end);
		const scheduleText = this.cfg.showScheduleText ? this._getSelectedScheduleRangeText() : "";
		const dateMetaText = scheduleText ? `${dateText} · ${scheduleText}` : dateText;
		const paxText = this._formatPassengers(this.state.form.people);

		const additionalsRowsHtml = quote.additionals?.length
			? quote.additionals
				.map((a) => `
        <div class="md-row md-row--tight">
          <div class="md-col">
            <div class="md-side__extra-name">${this._esc(a.name)}</div>
          </div>
          <div class="md-col-auto">
            <span class="md-side__money">${this._esc(a.priceBaseFormatted || a.priceFormatted || this._getFreeAdditionalLabel())}</span>
          </div>
        </div>
      `)
				.join("")
			: "";

		const commissionLineHtml =
			quote.hasOnlineCommission && quote.onlineCommissionBaseFormatted
				? `
        <div class="md-row md-row--tight">
          <div class="md-col">
            <div class="md-side__extra-name">${this._t("labels.serviceFee", "Service fee")}</div>
            ${quote.onlineCommissionPercent
					? `<div class="md-side__info">${this._esc(String(quote.onlineCommissionPercent))}% ${this._t("labels.appliedToAmountYouWillPayNow", "applied to the amount you will pay now")}</div>`
					: ""}
          </div>
          <div class="md-col-auto">
            <span class="md-side__money">${this._esc(quote.onlineCommissionBaseFormatted)}</span>
          </div>
        </div>
      `
				: "";

		const couponBlock = this.cfg.showPromoCode
			? `
      <div class="md-side__coupon">
        <div class="md-side__coupon-toggle">
          <a href="#"
            class="md-side__toggle-link"
            data-md-toggle="promo-code"
            aria-expanded="false">
            ${this._t("labels.promoCodeTitle", "Add a promotional code")}
          </a>
        </div>

        <div class="md-side__coupon-panel"
            data-md-toggle-panel="promo-code"
            data-md-open="0">
          <div class="md-row md-row--tight md-side__coupon-form">
            <div class="md-col md-field">
              <input
                type="text"
                class="md-input"
                name="promo_code"
                placeholder="${this._t("labels.promoCodePlaceholder", "Enter coupon")}"
                autocomplete="off"
              >
            </div>

            <div class="md-col-auto">
              <button type="button" class="md-btn md-btn--ghost">
                ${this._t("ui.apply", "Apply")}
              </button>
            </div>
          </div>
        </div>
      </div>
    `
			: "";

		const totalsGroupHtml =
			(
				quote.taxableAmountFormatted ||
				quote.vatFormatted ||
				quote.totalFormatted ||
				couponBlock
			)
				? `
        <div class="md-side__totals-group">
          ${quote.taxableAmountFormatted ? `
            <div class="md-row md-row--tight md-side__totals-line">
              <div class="md-col">
                <div class="md-side__extra-name">${this._t("labels.taxableAmount", "Taxable amount")}</div>
              </div>
              <div class="md-col-auto">
                <span class="md-side__money">${this._esc(quote.taxableAmountFormatted)}</span>
              </div>
            </div>
          ` : ""}

          ${quote.vatFormatted ? `
            <div class="md-row md-row--tight md-side__totals-line">
              <div class="md-col">
                <div class="md-side__extra-name">
                  ${this._t("labels.vat", "VAT")}${quote.vatPercentLabel ? ` (${this._esc(quote.vatPercentLabel)})` : ""}
                </div>
              </div>
              <div class="md-col-auto">
                <span class="md-side__money">${this._esc(quote.vatFormatted)}</span>
              </div>
            </div>
          ` : ""}

          ${quote.totalFormatted ? `
            <div class="md-row md-row--tight md-side__total-row">
              <div class="md-col">
                <div class="md-side__total-title">${this._t("labels.total", "Total")}</div>
              </div>
              <div class="md-col-auto">
                <div class="md-side__total-money">${this._esc(quote.totalFormatted)}</div>
              </div>
            </div>
          ` : ""}

          ${couponBlock}
        </div>
      `
				: "";

		const priceDetailsBlock = `
    <div class="md-side__section">
      <div class="md-row md-row--tight md-side__section-title">
        <div class="md-col">${this._t("labels.priceDetails", "Price details")}</div>
      </div>

      <div class="md-side__list">
        ${quote.rentalPriceBaseFormatted || quote.rentalPriceFormatted ? `
          <div class="md-row md-row--tight">
            <div class="md-col">
              <div class="md-side__extra-name">${this._t("labels.rentalPrice", "Rental price")}</div>
            </div>
            <div class="md-col-auto">
              <span class="md-side__money">${this._esc(quote.rentalPriceBaseFormatted || quote.rentalPriceFormatted)}</span>
            </div>
          </div>
        ` : ""}

        ${additionalsRowsHtml}
        ${commissionLineHtml}
      </div>

      ${totalsGroupHtml}
    </div>
  `;

		const showSplit =
			quote.prepaymentPercent != null &&
			quote.prepaymentPercent > 0 &&
			quote.prepaymentPercent < 100;

		const payNowHelpText = this._t("ui.payNowHelp", "This is the amount you will pay online now.");
		const payNowToggleText = this._t("ui.viewPaymentBreakdown", "View payment breakdown");

		const payNowBlock =
			showSplit && quote.payOnlineFormatted
				? `
        <div class="md-side__section md-side__paynow">
          <div class="md-row md-row--tight md-side__section-title">
            <div class="md-col">${this._t("labels.payNow", "Pay now")}</div>
            <div class="md-col-auto">
              <span class="md-side__paynow-money">${this._esc(quote.payOnlineFormatted)}</span>
            </div>
          </div>

          <div class="md-side__sub">
            ${payNowHelpText}
          </div>

          <div class="md-side__actions">
            <button
              type="button"
              class="md-side__toggle-link md-side__toggle-link--small"
              data-md-toggle="pay-now-breakdown"
              aria-expanded="false">
              ${this._esc(payNowToggleText)}
            </button>
          </div>

          ${typeof this._renderPayNowBreakdownPanel === "function" ? this._renderPayNowBreakdownPanel() : ""}
        </div>
      `
				: "";

		const payAtPortHelpText = this._t("ui.payAtPortHelp", "This is the amount to be paid at the port on the charter day.");
		const payLaterToggleText = this._t("ui.viewPendingBreakdown", "View pending breakdown");

		const payAtPortBlock =
			showSplit && quote.payOnSpotFormatted
				? `
        <div class="md-side__section">
          <div class="md-row md-row--tight md-side__section-title">
            <div class="md-col">${this._t("labels.payAtPort", "Pay at the port")}</div>
            <div class="md-col-auto">
              <span class="md-side__money">${this._esc(quote.payOnSpotFormatted)}</span>
            </div>
          </div>

          <div class="md-side__sub">
            ${payAtPortHelpText}
          </div>

          <div class="md-side__actions">
            <button
              type="button"
              class="md-side__toggle-link md-side__toggle-link--small"
              data-md-toggle="pay-later-breakdown"
              aria-expanded="false">
              ${this._esc(payLaterToggleText)}
            </button>
          </div>

          ${typeof this._renderPayLaterBreakdownPanel === "function" ? this._renderPayLaterBreakdownPanel() : ""}
        </div>
      `
				: "";

		const depositUI = typeof this._computeSecurityDepositUi === "function"
			? this._computeSecurityDepositUi()
			: { show: false };

		const fuelUI = typeof this._computeFuelUi === "function"
			? this._computeFuelUi()
			: { show: false };

		const depositBlock = depositUI?.show
			? `
      <div class="md-side__fine">
        <div class="md-row md-row--tight">
          <div class="md-col">${this._esc(depositUI.title || this._t("labels.securityDeposit", "Amount of the security deposit"))}</div>
          <div class="md-col-auto"><span class="md-side__money">${this._esc(depositUI.formatted || "")}</span></div>
        </div>
        ${depositUI.info ? `<div class="md-side__info">${this._esc(depositUI.info)}</div>` : ""}
      </div>
    `
			: "";

		const fuelBlock = fuelUI?.show
			? `
      <div class="md-side__fine">
        <div class="md-row md-row--tight">
          <div class="md-col">
            ${this._esc(fuelUI.title || this._t("labels.fuelNotIncluded", "Fuel not included"))}
          </div>

          ${fuelUI.showLearnMore
				? `
              <div class="md-col-auto">
                <button type="button"
                        class="md-side__toggle-link md-side__toggle-link--small"
                        data-md-toggle="fuel-learn-more"
                        aria-expanded="false">
                  ${this._esc(fuelUI.learnMoreLabel || this._t("ui.learnMore", "Learn more"))}
                </button>
              </div>
            `
				: ""
			}
        </div>

        ${fuelUI.info ? `<div class="md-side__info">${this._esc(fuelUI.info)}</div>` : ""}

        ${fuelUI.showLearnMore
				? `
            <div class="md-side__learnmore"
                 data-md-toggle-panel="fuel-learn-more"
                 data-md-open="0">
              ${this._esc(fuelUI.learnMoreText || this._t("ui.fuelLearnMore", "Fuel cost may be charged before or after boarding."))}
            </div>
          `
				: ""
			}
      </div>
    `
			: "";

		const calculatingHint = quote.isCalculating
			? `<div class="md-side__hint">${this._t("ui.calculating", "Calculating…")}</div>`
			: "";

		return `
    <div id="bookingSideBarCard" class="md-card">
      <div class="md-card__top">
        <div class="md-side__header">
          <div class="md-side__section-title">${title}</div>
        </div>
      </div>

      <div class="md-card__body">
        ${calculatingHint}

        <div class="md-side__meta">
          ${this._svg("map-marker")}
          <span>${port}</span>
        </div>

        <div class="md-side__meta">
          ${this._svg("calendar")}
          <span>${this._esc(dateMetaText)}</span>
        </div>

        <div class="md-side__meta">
          ${this._svg("users")}
          <span>${this._esc(paxText)}</span>
        </div>

        ${priceDetailsBlock}
        ${payNowBlock}
        ${payAtPortBlock}
        ${depositBlock}
        ${fuelBlock}
      </div>
    </div>
  `;
	}

	_renderStep2() {
		return `
      <div class="md-grid">
        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.firstName", "First name")}</label>
          <input type="text" data-md-field="first_name" value="${this._esc(this.state.form.first_name)}">
        </div>

        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.lastName", "Last name")}</label>
          <input type="text" data-md-field="last_name" value="${this._esc(this.state.form.last_name)}">
        </div>

        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.emailRequired", "Email *")}</label>
          <input type="email" required data-md-field="email" value="${this._esc(this.state.form.email)}">
        </div>

        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.country", "Country")}</label>
          <select data-md-field="country" data-md-country-select="1">
            <option value="">${this._t("ui.selectCountry", "Select country")}</option>
          </select>
        </div>

        <div class="md-col-6 md-field">
          <label class="md-field__label">${this._t("labels.phone", "Phone")}</label>
          <input type="tel" data-md-field="phone" data-md-phone-input="1" value="${this._esc(this.state.form.phone_raw || "")}">
          <div class="md-muted md-mt-8" data-md-phone-hint="1" style="display:none;"></div>
        </div>
      </div>
    `;
	}

	_renderStep3() {
		const apiDefault = this.state?.api?.payment?.default_method || null;

		// An empty key lets the API apply the tenant's default online method.
		const defaultKey = String(apiDefault?.key || "").trim();

		if (!this.state.form.payment_method) {
			this.state.form.payment_method = defaultKey;
		}

		const pm = String(this.state.form.payment_method || defaultKey).trim() || defaultKey;
		const checkedCard = pm === defaultKey ? "checked" : "";

		const title = this._t("labels.selectPaymentMethod", "Select a payment method");
		const group = String(apiDefault?.group || "").toLowerCase();

		const cardLabel =
			group === "credit-card"
				? "Credit Card"
				: apiDefault?.name
					? String(apiDefault.name)
					: this._t("labels.card", "Card");

		const termsAcceptanceFull = this._t(
			"labels.termsAcceptanceFull",
			"By selecting the following button, you unconditionally accept the Terms of Use and Rental Terms. You also agree to pay the total amount of the reservation."
		);

		const readTermsText = this._t(
			"labels.readTerms",
			"Read terms"
		);

		const termsExpanded = this.state.ui?.termsOpen === true;
		const termsAriaExpanded = termsExpanded ? "true" : "false";
		const termsHiddenAttr = termsExpanded ? "" : "hidden";
		const paymentIntroText = String(
			this.globalCfg?.booking?.paymentIntroText || this.globalCfg?.bookingOnline?.paymentIntroText || ""
		).trim();

		return `
    <div class="md-booking-summary-layout md-payment-layout">
      <div class="md-booking-summary-main md-payment-main">
        ${this._renderInlineStepTitle(3)}

        <div class="md-grid">
          <div class="md-col-12 md-field">
        ${paymentIntroText ? `<div class="md-payment-intro"><span class="md-payment-intro__icon" aria-hidden="true">${this._svg("info-circle")}</span><span class="md-payment-intro__text">${this._esc(paymentIntroText)}</span></div>` : ""}
        <label class="md-label">${title}:</label>

        <div class="md-payments" role="radiogroup" aria-label="${title}">
          <label class="md-payment-card">
            <input
              class="md-payment-card__input"
              type="radio"
              name="md_payment"
              value="${this._esc(defaultKey)}"
              data-md-field="payment_method"
              ${checkedCard}
            />
            <span class="md-payment-card__body">
              <span class="md-payment-card__icon" aria-hidden="true">
                ${this._svg("credit-card")}
              </span>

              <span class="md-payment-card__text">
                <span class="md-payment-card__title">${this._esc(cardLabel)}</span>
                ${apiDefault?.key
				? `<span class="md-payment-card__meta">${this._esc(apiDefault?.name || "")}</span>`
				: ""
			}
              </span>
            </span>
          </label>
        </div>
          </div>

          <div class="md-col-12 md-check">
            <div class="md-terms-card">
              <label class="md-check__label md-check__label--terms">
                <input
                  class="md-check__input md-check__input--lg"
                  type="checkbox"
                  data-md-field="accept_terms"
                  ${this.state.form.accept_terms ? "checked" : ""}
                />

                <span class="md-check__content">
                  <span class="md-check__text md-check__text--terms">
                    ${this._esc(termsAcceptanceFull)}
                  </span>

                  <button type="button"
                          class="md-terms__toggle"
                          data-md-action="toggle-terms"
                          aria-expanded="${termsAriaExpanded}"
                          aria-controls="mdTermsCollapse">
                    ${this._esc(readTermsText)}
                  </button>
                </span>
              </label>

              <div id="mdTermsCollapse" class="md-terms" ${termsHiddenAttr}>
                <div class="md-terms__box">
                  <div class="md-terms__content">
                    ${this._renderTermsText()}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <aside class="md-booking-summary-sidebar md-payment-sidebar">
        ${this._renderBookingSidebarCard()}
      </aside>
    </div>
  `;
	}

	_renderTermsText() {
		return `
    <div
      class="md-terms__remote-content"
      data-md-terms-content="1"
      data-md-terms-loaded="0"
      data-md-terms-loading="0"
    >
      <p>${this._esc(this._t("ui.clickToLoadTerms", "Open this section to load the rental terms."))}</p>
    </div>
  `;
	}

	_bindStep3Events(containerEl) {
		if (!containerEl) return;

		const toggle = containerEl.querySelector('[data-md-action="toggle-terms"]');
		if (toggle && !toggle.__mdBound) {
			toggle.addEventListener("click", async (e) => {
				e.preventDefault();

				const next = !(this.state.ui?.termsOpen === true);
				if (!this.state.ui) this.state.ui = {};

				this.state.ui.termsOpen = next;
				toggle.setAttribute("aria-expanded", next ? "true" : "false");

				const termsPanelEl = containerEl.querySelector(".md-terms");
				if (!termsPanelEl) {
					return;
				}

				termsPanelEl.hidden = !next;

				if (!next) {
					return;
				}

				const termsContentEl = termsPanelEl.querySelector('[data-md-terms-content="1"]');
				if (!termsContentEl) {
					return;
				}

				const alreadyLoaded = termsContentEl.getAttribute("data-md-terms-loaded") === "1";
				const isLoading = termsContentEl.getAttribute("data-md-terms-loading") === "1";

				if (alreadyLoaded || isLoading) {
					return;
				}

				termsContentEl.setAttribute("data-md-terms-loading", "1");
				termsContentEl.innerHTML = `<p>${this._esc(this._t("ui.loadingTerms", "Loading terms…"))}</p>`;

				try {
					const rentalTermsHtml = await this._getRentalTerms("2");

					termsContentEl.innerHTML = rentalTermsHtml !== ""
						? rentalTermsHtml
						: `<p>${this._esc(this._t("ui.noTermsAvailable", "No rental terms available."))}</p>`;

					termsContentEl.setAttribute("data-md-terms-loaded", "1");
				} catch (error) {
					termsContentEl.innerHTML = `<p>${this._esc(error?.message || this._t("errors.rentalTermsError", "Could not load rental terms."))}</p>`;
				} finally {
					termsContentEl.setAttribute("data-md-terms-loading", "0");
				}
			});

			toggle.__mdBound = true;
		}
	}

	_renderStep4() {
		const returnData = this.state.paymentReturn || {};
		const booking = returnData.booking || {};
		const payment = returnData.payment || {};
		const customer = returnData.customer || {};
		const restore = returnData.booking_restore || {};

		const boat = this._extractSidebarBoatData();
		const quote = this._extractSidebarQuoteData();

		const bookingReference = booking?.reference || this.state.uuid_shop_cart || "—";
		const bookingService = booking?.service || boat?.title || "—";
		const bookingLocation = booking?.location || boat?.port || "—";
		const bookingDate =
			booking?.date ||
			this._formatDateRangeHuman(
				restore?.date_start || this.state.form.date_start,
				restore?.date_end || this.state.form.date_end
			) ||
			"—";

		const bookingCustomer =
			booking?.customer ||
			[customer?.first_name, customer?.last_name].filter(Boolean).join(" ").trim() ||
			[this.state.form.first_name, this.state.form.last_name].filter(Boolean).join(" ").trim() ||
			"—";

		const bookingUrl = booking?.url || "";
		const paymentReference = payment?.reference || "—";
		const paidAmount =
			payment?.total_price ||
			quote?.payOnlineFormatted ||
			quote?.totalFormatted ||
			"—";

		const email = customer?.email || this.state.form.email || "—";
		const phone = customer?.phone || this.state.form.phone || "—";

		const img = boat.imageUrl
			? `<img src="${this._esc(boat.imageUrl)}" class="md-finish__img" alt="">`
			: "";

		return `
    <div class="md-finish md-finish--success">
      <div class="md-finish__hero">
        <div class="md-finish__icon">✓</div>
        <div class="md-finish__hero-text">
          <div class="md-finish__title">
            ${this._t("labels.bookingConfirmedTitle", "Reservation confirmed")}
          </div>
          <div class="md-finish__text">
            ${this._t(
			"labels.bookingConfirmedText",
			"Your reservation is now confirmed. Below you can review your booking and payment details."
		)}
          </div>
        </div>
      </div>

      <div class="md-finish__card">
        ${img ? `<div class="md-finish__media">${img}</div>` : ""}

        <div class="md-finish__section">
          <div class="md-finish__section-title">
            ${this._t("labels.bookingSummary", "Booking summary")}
          </div>

          ${this._renderFinishRow(this._t("labels.bookingReference", "Booking reference"), bookingReference)}
          ${this._renderFinishRow(this._t("labels.service", "Service"), bookingService)}
          ${this._renderFinishRow(this._t("labels.location", "Location"), bookingLocation)}
          ${this._renderFinishRow(this._t("labels.date", "Date"), bookingDate)}
          ${this._renderFinishRow(this._t("labels.customer", "Customer"), bookingCustomer)}
        </div>

        <div class="md-finish__section">
          <div class="md-finish__section-title">
            ${this._t("labels.paymentSummary", "Payment summary")}
          </div>

          ${this._renderFinishRow(this._t("labels.paymentReference", "Payment reference"), paymentReference)}
          ${this._renderFinishRow(this._t("labels.amountPaid", "Amount paid"), paidAmount)}
        </div>

        <div class="md-finish__section">
          <div class="md-finish__section-title">
            ${this._t("labels.contactDetails", "Contact details")}
          </div>

          ${this._renderFinishRow(this._t("labels.email", "Email"), email)}
          ${this._renderFinishRow(this._t("labels.phone", "Phone"), phone)}
          ${bookingUrl ? this._renderFinishRow(this._t("labels.bookingDetails", "Booking details"), bookingUrl, true) : ""}
        </div>
      </div>
    </div>
  `;
	}

	_renderFinishRow(label, value, isLink = false) {
		const safeLabel = this._esc(label || "—");
		const safeValue = this._esc(value || "—");

		if (isLink && value) {
			return `
      <div class="md-finish__row">
        <div class="md-finish__label">${safeLabel}</div>
        <div class="md-finish__value">
          <a href="${this._esc(value)}" target="_blank" rel="noopener noreferrer" class="md-link">
            ${this._t("ui.viewDetails", "View details")}
          </a>
        </div>
      </div>
    `;
		}

		return `
    <div class="md-finish__row">
      <div class="md-finish__label">${safeLabel}</div>
      <div class="md-finish__value">${safeValue}</div>
    </div>
  `;
	}

	_onFieldEvent(e) {
		const t = e?.target;
		if (!t) return;

		if (t.matches('input[data-md-range="date_range_booking"]')) return;
		if (t.classList && t.classList.contains("flatpickr-input")) return;

		this._clearInlineErrorForElement(t);

		if (t.matches && t.matches('input[type="checkbox"][data-md-additional-checkbox="1"]')) {
			const idOnGi = this._toInt(t.getAttribute("data-md-id-additional-on-gi"), 0);
			if (idOnGi) {
				if (!this.state.form.additionals_selected) this.state.form.additionals_selected = {};

				const skipperOpt = this._getOptionalSkipperOnGiInfo();
				if (skipperOpt?.idOnGi && skipperOpt.idOnGi === idOnGi) {
					this.state.form.hire_skipper = t.checked ? "yes" : "no";

					const sel = this.stepContainer?.querySelector('select[data-md-field="hire_skipper"]');
					if (sel) sel.value = this.state.form.hire_skipper;
				}

				this.state.form.additionals_selected[idOnGi] = !!t.checked;
				this.schedulePriceOnBooking();
			}
			return;
		}

		const key = t.getAttribute("data-md-field");
		if (!key) return;

		if (key === "full_day") {
			const next = !!t.checked;
			this.state.form.full_day = next;

			if (next) {
				this.state.form.timeslot_id = null;
				this.state.form.timeslot = null;

				const wrap = this.stepContainer?.querySelector('[data-md-timeslot-select-wrap="1"]');
				if (wrap) wrap.style.display = "none";
			} else {
				const wrap = this.stepContainer?.querySelector('[data-md-timeslot-select-wrap="1"]');
				if (wrap) wrap.style.display = "";
			}

			if (this.state.currentStep === 1) this.schedulePriceOnBooking();
			return;
		}

		if (key === "timeslot_id") {
			const v = (t.value || "").trim();
			this.state.form.timeslot_id = v !== "" ? v : null;

			const activeRange = this._getActivePriceRangeFromPriceOnBooking();
			const timeslots = Array.isArray(activeRange?.timeslots) ? activeRange.timeslots : [];
			const selected = timeslots.find((ts) => String(ts?.id) === String(this.state.form.timeslot_id)) || null;
			this.state.form.timeslot = selected;

			const sideCard = this.stepContainer?.querySelector("#bookingSideBarCard");
			if (sideCard) sideCard.outerHTML = this._renderBookingSidebarCard();

			if (this.state.currentStep === 1) this.schedulePriceOnBooking();
			return;
		}

		if (key === "hire_skipper") {
			const v = String(t.value || "no");
			this.state.form.hire_skipper = v === "yes" ? "yes" : "no";

			const skipperOpt = this._getOptionalSkipperOnGiInfo();
			if (skipperOpt?.idOnGi) {
				if (!this.state.form.additionals_selected) this.state.form.additionals_selected = {};
				this.state.form.additionals_selected[skipperOpt.idOnGi] = this.state.form.hire_skipper === "yes";

				const cb = this.stepContainer?.querySelector(
					`input[type="checkbox"][data-md-additional-checkbox="1"][data-md-id-additional-on-gi="${skipperOpt.idOnGi}"]`
				);
				if (cb) cb.checked = this.state.form.hire_skipper === "yes";

				this.schedulePriceOnBooking();
				this._renderOrUpdateSkipperPrompt();
			}
			return;
		}

		if (t.type === "checkbox") {
			this.state.form[key] = t.checked;

			if (key === "children_included") {
				if (this.cfg.showChildrenIncluded !== true) {
					this.state.form.children_included = false;
					this.state.form.pax_children = 0;
					return;
				}

				if (!this.state.form.children_included) {
					this.state.form.pax_children = 0;
					const wrap = this.stepContainer?.querySelector('[data-md-children-wrap="1"]');
					if (wrap) wrap.style.display = "none";
				} else {
					const wrap = this.stepContainer?.querySelector('[data-md-children-wrap="1"]');
					if (wrap) wrap.style.display = "";
					this._syncChildrenSelectOptions();
				}
			}
		} else if (key === "people") {
			this.state.form[key] = this._toInt(t.value, 1);

			if (this.cfg.showChildrenIncluded === true && this.state.form.children_included) {
				this._syncChildrenSelectOptions();
			} else {
				this.state.form.pax_children = 0;
			}
		} else if (key === "pax_children") {
			this.state.form.pax_children = this._toInt(t.value, 0);

			const peopleVal = this._getCurrentPeopleValue();
			const maxChildren = this._getMaxChildrenForPeople(peopleVal);
			if (this.state.form.pax_children > maxChildren) this.state.form.pax_children = maxChildren;
			if (this.state.form.pax_children < 0) this.state.form.pax_children = 0;
			if (t.value !== String(this.state.form.pax_children)) t.value = String(this.state.form.pax_children);
		} else if (key === "message") {
			this.state.form.message = String(t.value || "");
		} else {
			this.state.form[key] = String(t.value || "");
		}

		if (this.state.currentStep === 1) {
			this.schedulePriceOnBooking();
		}

		if (key === "country") {
			this.state.form.country = String(t.value || "").toUpperCase();
			return;
		}

		if (key === "phone") {
			this._syncPhoneFromIti();
			return;
		}
	}

	_validateStep(step) {
		this._clearInlineErrors();

		if (step === 1) {
			if (!this.state.form.date_start || !this.state.form.date_end) {
				this._showFieldErrorAndScroll(
					'input[data-md-range="date_range_booking"]',
					this._t("errors.selectDates", this.cfg.calendarSelectionMode === "single" ? "Please select a date." : "Please select dates.")
				);
				return false;
			}

			const ds = String(this.state.form.date_start || "").trim();
			const de = String(this.state.form.date_end || "").trim();
			const isMultiDay = ds && de && ds !== de;
			const isSingleMode = this.cfg.calendarSelectionMode === "single";

			if (isMultiDay) {
				this.state.form.full_day = true;
				this.state.form.timeslot_id = null;
				this.state.form.timeslot = null;
				return true;
			}

			if (isSingleMode) {
				this.state.form.date_end = ds;
			}

			const activeRange = this._getActivePriceRangeFromPriceOnBooking();
			const rangeTimeslots = Array.isArray(activeRange?.timeslots) ? activeRange.timeslots : null;
			const timeslots = Array.isArray(rangeTimeslots)
				? this._getAvailableTimeSlotsForDate(ds, rangeTimeslots)
				: null;

			if (Array.isArray(rangeTimeslots) && rangeTimeslots.length > 0) {
				const canFullDay = this._canBookFullDay(rangeTimeslots, timeslots);

				if (!this.state.form.full_day) {
					const selectedTimeslotId = this._toInt(this.state.form.timeslot_id ?? 0, 0);
					const selectedTimeslotAvailable = timeslots.some((ts) => {
						return this._toInt(ts?.id ?? ts?.id_time_slot ?? 0, 0) === selectedTimeslotId;
					});

					if (!selectedTimeslotId || !selectedTimeslotAvailable) {
						this._showFieldErrorAndScroll(
							'[data-md-field="timeslot_id"]',
							selectedTimeslotId || timeslots.length === 0
								? this._t("errors.selectedTimeslotUnavailable", "The selected schedule is no longer available. We refreshed the available schedules.")
								: this._t("errors.selectTimeslot", "Please select a schedule.")
						);
						return false;
					}
				} else {
					if (!canFullDay) {
						this._showFieldErrorAndScroll(
							'[data-md-field="full_day"]',
							this._t("errors.fullDayNotAllowed", "Full day is not available for this date.")
						);
						return false;
					}
				}
			}

			return true;
		}

		if (step === 2) {
			if (!this.state.form.email) {
				this._showFieldErrorAndScroll(
					'[data-md-field="email"]',
					this._t("errors.emailRequired", "Email is required.")
				);
				return false;
			}
			return true;
		}

		if (step === 3) {
			if (!this.state.form.accept_terms) {
				this._showFieldErrorAndScroll(
					'[data-md-field="accept_terms"]',
					this._t("errors.mustAcceptTerms", "You must accept terms and conditions."),
					{ afterSelector: '.md-check__label--terms' }
				);
				return false;
			}
			return true;
		}

		return true;
	}

	async _hydrateBoatOnOpen({ forceRefresh = false } = {}) {
		let resolvedBoatId = String(this.cfg.boatId || "").trim();

		if (!resolvedBoatId) {
			resolvedBoatId = this._readBoatIdFromTopPanel();
			if (resolvedBoatId) {
				this.cfg.boatId = resolvedBoatId;
				this.wrapper.setAttribute("data-boat-id", resolvedBoatId);
			}
		}

		if (!resolvedBoatId) return;

		const now = Date.now();
		const cached = this._boatCache[resolvedBoatId];
		if (!forceRefresh && cached && now - cached.ts < this._boatCacheTtlMs) {
			this.state.boat_data = cached.data;
			const cap = this._extractBoatCapacity(cached.data);
			if (cap) this._applyCapacity(cap);
			return;
		}

		const url = this._buildBoatEndpoint(resolvedBoatId, {
			lang: this.cfg.lang || "",
			expand: this.cfg.expand || "",
		});

		try {
			this.setLoading(true);
			const raw = await this.api.getJson(url, {
				nonce: this.cfg.restNonce,
				cache: forceRefresh ? "no-store" : "default",
			});
			const data = this._unwrapBoatResponse(raw);

			this._boatCache[resolvedBoatId] = { ts: now, data };
			this.state.boat_data = data;

			const cap = this._extractBoatCapacity(data);
			if (cap) this._applyCapacity(cap);
		} finally {
			this.setLoading(false);
		}
	}

	_applyCapacity(cap) {
		this.state.boat_capacity = cap;
		const p = this._toInt(this.state.form.people || 1, 1);
		if (p > cap) this.state.form.people = cap;
		if (p < 1) this.state.form.people = 1;
	}

	setLoading(isLoading) {
		this.wrapper.dataset.mdLoading = isLoading ? "1" : "0";
		this._qsa("[data-md-step-container] input, [data-md-step-container] select, [data-md-step-container] button, [data-md-step-container] textarea").forEach(
			(el) => {
				if (el) el.disabled = !!isLoading;
			}
		);
	}

	_setNavigationBusy(isBusy) {
		const busy = !!isBusy;

		this._bookingStepBusy = busy;

		if (this.nextBtn) {
			this.nextBtn.disabled = busy;
			this.nextBtn.classList.toggle("is-loading", busy);
			this.nextBtn.setAttribute("aria-busy", busy ? "true" : "false");
			this.nextBtn.setAttribute("aria-disabled", busy ? "true" : "false");
		}

		if (this.backBtn) {
			this.backBtn.disabled = busy;
			this.backBtn.setAttribute("aria-disabled", busy ? "true" : "false");
		}
	}

	_openModal() {
		if (!this.modal) return;
		this._lastFocusedBeforeOpen = document.activeElement instanceof HTMLElement
			? document.activeElement
			: null;
		this._ensureModalPortal();
		if ("inert" in this.modal) {
			this.modal.inert = false;
		}
		this.modal.classList.add("is-open");
		this.modal.setAttribute("aria-hidden", "false");
		document.documentElement.classList.add("md-modal-open");
		document.body.classList.add("md-modal-open");
	}

	_closeModal() {
		if (!this.modal) return;
		this._moveFocusOutsideModal();
		this.modal.classList.remove("is-open");
		this.modal.setAttribute("aria-hidden", "true");
		if ("inert" in this.modal) {
			this.modal.inert = true;
		}
		document.documentElement.classList.remove("md-modal-open");
		document.body.classList.remove("md-modal-open");
	}

	_ensureModalPortal() {
		if (!this.modal || !document.body) {
			return;
		}

		this.modal.classList.add("md-booking");
		this.modal.setAttribute("data-md-modal-portal", "body");

		if (this.modal.parentElement === document.body) {
			return;
		}

		if (!this._modalPortalPlaceholder && this.modal.parentNode) {
			this._modalOriginalParent = this.modal.parentNode;
			this._modalPortalPlaceholder = document.createComment("maradigma booking modal portal");
			this.modal.parentNode.insertBefore(this._modalPortalPlaceholder, this.modal);
		}

		document.body.appendChild(this.modal);
	}

	_moveFocusOutsideModal() {
		const active = document.activeElement;
		const activeInsideModal = !!(
			active &&
			active instanceof HTMLElement &&
			this.modal &&
			this.modal.contains(active)
		);

		if (!activeInsideModal) {
			return;
		}

		const focusTarget = (
			this._lastFocusedBeforeOpen &&
			this._lastFocusedBeforeOpen instanceof HTMLElement &&
			document.contains(this._lastFocusedBeforeOpen)
		)
			? this._lastFocusedBeforeOpen
			: this.openBtn;

		if (focusTarget && typeof focusTarget.focus === "function") {
			try {
				focusTarget.focus({ preventScroll: true });
				return;
			} catch {
				focusTarget.focus();
				return;
			}
		}

		active.blur();
	}

	_setStepTitle(step) {
		if (!this.stepTitleEl) return;
		this.stepTitleEl.textContent = this.stepsCfg.steps[step]?.title || "";
	}

	_syncStepTitlePresentation(step) {
		if (!this.stepTitleEl) return;

		if (step === 4 || this._usesInlineStepTitle(step)) {
			this.stepTitleEl.textContent = "";
			this.stepTitleEl.style.display = "none";
			return;
		}

		this.stepTitleEl.style.display = "";
		this._setStepTitle(step);
	}

	_usesInlineStepTitle(step) {
		return step === 1 || step === 3;
	}

	_renderInlineStepTitle(step) {
		const title = this.stepsCfg.steps[step]?.title || "";
		if (!title) return "";

		return `<div class="md-step-title md-step-title--inline">${this._esc(title)}</div>`;
	}

	_updateProgress(step) {
		this._qsa("[data-md-step-pill]").forEach((pill) => {
			const s = this._toInt(pill.getAttribute("data-md-step-pill"), 0);
			pill.classList.remove("is-active", "is-complete");
			if (s < step) pill.classList.add("is-complete");
			if (s === step) pill.classList.add("is-active");
		});
	}

	_show(el, msg) {
		if (!el) return;
		let out = msg;
		if (out && typeof out === "object") {
			try {
				out = JSON.stringify(out);
			} catch {
				out = String(out);
			}
		}
		el.textContent = String(out || "");
		el.classList.add("is-show");
	}

	_hide(el) {
		if (!el) return;
		el.textContent = "";
		el.classList.remove("is-show");
	}

	_getScrollableContainer() {
		const candidates = [
			this.modal?.querySelector?.("[data-md-modal-scroll]"),
			this.modal?.querySelector?.(".md-modal__body"),
			this.modal?.querySelector?.(".md-modal__content"),
			this.modal?.querySelector?.(".md-modal__dialog"),
			this.modal,
		];

		for (const el of candidates) {
			if (el && typeof el.scrollTo === "function") {
				return el;
			}
		}

		return this.modal || document.documentElement;
	}

	_scrollToElementInModal(targetEl, { offset = 16, behavior = "smooth", block = "center" } = {}) {
		if (!targetEl) return;

		const container = this._getScrollableContainer();
		if (!container || container === document.documentElement || container === document.body) {
			try {
				targetEl.scrollIntoView({ behavior, block });
			} catch { }
			return;
		}

		try {
			const targetRect = targetEl.getBoundingClientRect();
			const containerRect = container.getBoundingClientRect();
			const currentScroll = container.scrollTop || 0;
			const targetScroll = currentScroll + (targetRect.top - containerRect.top) - offset;

			container.scrollTo({
				top: Math.max(0, targetScroll),
				behavior,
			});
		} catch {
			try {
				targetEl.scrollIntoView({ behavior, block });
			} catch { }
		}
	}

	_scrollModalBodyToTop(behavior = "auto") {
		const scrollContainer =
			this.modal?.querySelector(".md-modal__body") ||
			this.modal;

		if (!scrollContainer) {
			return;
		}

		try {
			scrollContainer.scrollTo({
				top: 0,
				behavior,
			});
		} catch {
			scrollContainer.scrollTop = 0;
		}
	}

	_showErrorAndScroll(message) {
		if (!this.errBox) return;

		this._show(this.errBox, this._toPublicErrorMessage(message));

		requestAnimationFrame(() => {
			this._scrollToElementInModal(this.errBox, { offset: 16, behavior: "smooth", block: "center" });

			try {
				this.errBox.setAttribute("tabindex", "-1");
				this.errBox.focus({ preventScroll: true });
			} catch { }

			this.errBox.classList.add("md-alert--shake");

			window.setTimeout(() => {
				try {
					this.errBox.classList.remove("md-alert--shake");
				} catch { }
			}, 700);
		});
	}

	_showBookingRequestError(error) {
		if (this._isBookingSessionExpiredError(error)) {
			this._showBookingSessionExpiredAndScroll();
			return;
		}

		this._showErrorAndScroll(
			error?.message || this._t("errors.bookingError", "Booking error")
		);
	}

	_isBookingSessionExpiredError(error) {
		const code = String(
			error?.data?.code ||
			error?.data?.error?.code ||
			error?.code ||
			""
		).trim();

		return code === "maradigma_booking_invalid_nonce";
	}

	_showBookingSessionExpiredAndScroll() {
		this._showErrorAndScroll(
			this._t("errors.bookingSessionExpired", "The session has expired.")
		);

		if (!this.errBox) {
			return;
		}

		const actions = document.createElement("div");
		actions.className = "md-alert__actions";

		const reloadButton = document.createElement("button");
		reloadButton.type = "button";
		reloadButton.className = "md-btn md-btn--primary md-alert__reload";
		reloadButton.textContent = this._t("ui.reloadPage", "Reload page");
		reloadButton.addEventListener("click", () => {
			window.location.reload();
		});

		actions.appendChild(reloadButton);
		this.errBox.appendChild(actions);
	}

	_toPublicErrorMessage(message) {
		const raw = String(message || "").trim();
		const lower = raw.toLowerCase();

		const isTechnicalServerError =
			lower.includes("invalid json response") ||
			lower.includes("http 500") ||
			lower.includes("http 502") ||
			lower.includes("http 503") ||
			lower.includes("http 504") ||
			lower.includes("service unavailable") ||
			lower.includes("gateway timeout") ||
			lower.includes("internal server error") ||
			lower.includes("unexpected token") ||
			lower.includes("failed to fetch") ||
			lower.includes("networkerror") ||
			lower.includes("load failed") ||
			lower.includes("request timed out") ||
			lower.includes("timeouterror") ||
			lower.includes("aborterror") ||
			lower.includes("signal is aborted") ||
			lower.includes("operation was aborted");

		if (isTechnicalServerError) {
			this._logFrontendTechnicalError(raw);

			return this._t(
				"errors.temporaryUnavailable",
				"We cannot process your request right now. Please try again later."
			);
		}

		return raw || this._t("errors.bookingError", "Booking error");
	}

	_logFrontendTechnicalError(message) {
		const ajaxUrl = String(this.globalCfg?.ajaxUrl || "").trim();
		if (!ajaxUrl || !message) return;

		try {
			const body = new URLSearchParams();
			body.set("action", "maradigma_frontend_log");
			body.set("nonce", String(this.globalCfg?.nonce || ""));
			body.set("context", "booking-modal");
			body.set("message", String(message).slice(0, 500));
			body.set("url", window.location.href || "");

			if (navigator && typeof navigator.sendBeacon === "function") {
				navigator.sendBeacon(ajaxUrl, body);
				return;
			}

			fetch(ajaxUrl, {
				method: "POST",
				credentials: "same-origin",
				body,
				keepalive: true,
			}).catch(() => { });
		} catch { }
	}

	_clearInlineErrors() {
		if (!this.stepContainer) return;

		this.stepContainer
			.querySelectorAll("[data-md-inline-error]")
			.forEach((el) => el.remove());

		this.stepContainer
			.querySelectorAll(".md-field--error, .md-check--error")
			.forEach((el) => {
				el.classList.remove("md-field--error");
				el.classList.remove("md-check--error");
			});

		this.stepContainer
			.querySelectorAll(".md-field--highlight")
			.forEach((el) => {
				el.classList.remove("md-field--highlight");
			});
	}

	_clearInlineErrorForElement(el) {
		if (!el) return;

		const wrapper =
			el.closest(".md-field") ||
			el.closest(".md-check") ||
			el.closest(".md-col-12");

		if (!wrapper) return;

		wrapper.classList.remove("md-field--error", "md-check--error");

		const inlineErr = wrapper.querySelector("[data-md-inline-error]");
		if (inlineErr) inlineErr.remove();

		if (el.classList) {
			el.classList.remove("md-field--highlight");
		}
	}

	_showInlineFieldError(selector, message, options = {}) {
		if (!this.stepContainer || !selector) return null;

		const target = this.stepContainer.querySelector(selector);
		if (!target) return null;

		const wrapper =
			target.closest(".md-field") ||
			target.closest(".md-check") ||
			target.closest(".md-col-12") ||
			target.parentElement;

		if (!wrapper) return null;

		wrapper.classList.add(
			wrapper.classList.contains("md-check") ? "md-check--error" : "md-field--error"
		);

		const old = wrapper.querySelector("[data-md-inline-error]");
		if (old) old.remove();

		const errorEl = document.createElement("div");
		errorEl.className = "md-inline-error";
		errorEl.setAttribute("data-md-inline-error", "1");
		errorEl.textContent = String(message || "");

		const insertAfter =
			options.afterSelector
				? wrapper.querySelector(options.afterSelector)
				: target;

		if (insertAfter && insertAfter.parentNode) {
			insertAfter.insertAdjacentElement("afterend", errorEl);
		} else {
			wrapper.appendChild(errorEl);
		}

		return { wrapper, target, errorEl };
	}

	_showFieldErrorAndScroll(selector, message, options = {}) {
		this._showErrorAndScroll(message);

		const resolvedSelector =
			selector === 'input[data-md-range="date_range_booking"]'
				? (
					this.cfg.calendarDisplay === "inline"
						? '[data-md-calendar-inline="1"]'
						: 'input[data-md-range="date_range_booking"]'
				)
				: selector;

		const refs = this._showInlineFieldError(resolvedSelector, message, options);
		const targetEl = refs?.errorEl || refs?.wrapper || refs?.target || null;

		if (!targetEl) return;

		requestAnimationFrame(() => {
			this._scrollToElementInModal(targetEl, {
				offset: 24,
				behavior: "smooth",
				block: "center",
			});

			const focusTarget = refs?.target || null;
			if (focusTarget) {
				const canFocus =
					typeof focusTarget.focus === "function" &&
					!focusTarget.hasAttribute?.("disabled");

				if (canFocus) {
					try {
						focusTarget.focus({ preventScroll: true });
					} catch {
						try {
							focusTarget.focus();
						} catch { }
					}
				}

				if (focusTarget.classList) {
					focusTarget.classList.add("md-field--highlight");
					window.setTimeout(() => {
						try {
							focusTarget.classList.remove("md-field--highlight");
						} catch { }
					}, 1200);
				}
			}
		});
	}

	_qs(sel) {
		if (this.wrapper) {
			const foundInWrapper = this.wrapper.querySelector(sel);
			if (foundInWrapper) {
				return foundInWrapper;
			}
		}

		if (this.modal && this.modal.parentNode && !this.wrapper?.contains?.(this.modal)) {
			const foundInModal = this.modal.querySelector(sel);
			if (foundInModal) {
				return foundInModal;
			}
		}

		return null;
	}

	_qsa(sel) {
		const found = [];
		const seen = new Set();
		const collect = (root) => {
			if (!root || typeof root.querySelectorAll !== "function") {
				return;
			}

			Array.from(root.querySelectorAll(sel)).forEach((el) => {
				if (!seen.has(el)) {
					seen.add(el);
					found.push(el);
				}
			});
		};

		collect(this.wrapper);

		if (this.modal && this.modal.parentNode && !this.wrapper?.contains?.(this.modal)) {
			collect(this.modal);
		}

		return found;
	}

	_getI18n() {
		const raw =
			window && window.MaradigmaI18n && typeof window.MaradigmaI18n === "object"
				? window.MaradigmaI18n
				: {};

		const cfgLang = String(this.cfg?.lang || "").trim().toLowerCase();
		const cfgLocale = String(
			this.wrapper?.getAttribute("data-locale") ||
			raw.locale ||
			""
		).trim();

		const defaults = {
			lang: cfgLang || String(raw.lang || "en").trim().toLowerCase(),
			locale: cfgLocale || String(raw.locale || "en-GB").trim(),

			steps: {
				step1: "Specify the reservation",
				step2: "Your profile information",
				step3: "Payment method",
				step4: "Reservation confirmed",
			},
			labels: {
				dateRange: "Dates",
				fullDayCharter: "Full day charter",
				fullDayCharterHelp: "Select full day if you want the boat for the entire day. Otherwise, choose a half-day schedule.",
				selectTimeslot: "Select a schedule",
				selectTimeslotHelp: "Choose your preferred half-day schedule for this day charter.",
				people: "People",
				firstName: "First name",
				lastName: "Last name",
				emailRequired: "Email *",
				phone: "Phone",
				country: "Country",
				paymentMethod: "Payment method",
				card: "Card",
				acceptTerms: "I accept terms and conditions",

				total: "Total",
				extras: "Extras",
				toBePaidOnline: "To be paid online",
				toBePaidOnSpot: "To be paid on the spot",
				totalBookingExtras: "Total booking + extras",
				prepaymentPercent: "Prepayment",

				securityDeposit: "Amount of the security deposit",
				fuelNotIncluded: "Fuel not included",
				mandatory: "Mandatory",
				included: "Included",

				childrenIncluded: "Including children onboard",
				chooseChildren: "Choose the number of children",
				hireSkipperTitle: "Would you like a professional skipper on board?",
				hireSkipperHelp: "Recommended for a stress-free trip, especially if you're not fully familiar with the area or docking.",
				optionalMessage: "Optional message",
				optionalMessageHelp: "You can specify your project (schedule, program, particular needs)",

				promoCodeTitle: "Add a promotional code",
				promoCodePlaceholder: "Enter coupon",
				selectPaymentMethod: "Select a payment method",

				priceDetails: "Price details",
				rentalPrice: "Rental price",
				serviceFee: "Service fee",
				appliedToAmountYouWillPayNow: "applied to the amount you will pay now",
				taxableAmount: "Taxable amount",
				vatIncluded: "VAT included",
				vat: "VAT",
				payNow: "Pay now",
				payAtPort: "Pay at the port",

				termsAcceptanceFull: "By selecting the following button, you unconditionally accept the Terms of Use and Rental Terms. You also agree to pay the total amount of the reservation.",
				readTerms: "Read terms",

				bookingConfirmedTitle: "Reservation confirmed",
				bookingConfirmedText: "Your payment has been confirmed and your booking has been created successfully.",
				bookingSummary: "Booking summary",
				bookingReference: "Booking reference",
				service: "Service",
				location: "Location",
				date: "Date",
				customer: "Customer",
				paymentSummary: "Payment summary",
				paymentReference: "Payment reference",
				amountPaid: "Amount paid",
				contactDetails: "Contact details",
				email: "Email",
				bookingDetails: "Booking details",
			},
			ui: {
				calculating: "Calculating…",
				loadingTerms: "Loading terms…",
				notCalculated: "Not calculated",
				selectDates: "Select dates",
				messagePlaceholder: "Write your message (optional)…",
				continue: "Continue",
				continueToPayment: "Continue to payment",
				back: "Back",
				cancel: "Cancel",
				redirecting: "Redirecting…",
				bookingCreatedNoUrl: "Booking created, but no payment URL was returned.",
				learnMore: "Learn more",
				fuelLearnMore: "Fuel cost may be charged before or after boarding.",
				fuelIncluded: "Fuel included",
				fuelIncludedInfo: "Fuel is already included in the rental price, so you won’t need to pay it separately.",
				yesRecommended: "Yes (recommended)",
				no: "No",
				apply: "Apply",
				selectCountry: "Select country",
				viewBookingDetails: "View booking details",
				viewDetails: "View details",
				close: "Close",
				free: "Free",
				dateAvailable: "Available",
				dateUnavailable: "Not available",
				datePast: "Past date",
				payNowHelp: "This is the amount you will pay online now.",
				payAtPortHelp: "This is the amount to be paid at the port on the charter day.",
				viewPaymentBreakdown: "View payment breakdown",
				viewPendingBreakdown: "View pending breakdown",
				subtotalToPayNow: "Subtotal to pay now",
				totalToPayNow: "Total to pay now",
				totalPending: "Total pending",
				reloadPage: "Reload page",
			},
			errors: {
				selectTimeslot: "Please select a schedule.",
				selectedTimeslotUnavailable: "The selected schedule is no longer available. We refreshed the available schedules.",
				fullDayNotAllowed: "Full day is not available for this date.",
				emailRequired: "Email is required.",
				selectDates: "Please select dates.",
				mustAcceptTerms: "You must accept terms and conditions.",
				bookingEndpointMissing: "Booking endpoint not configured.",
				priceOnBookingError: "Price calculation error",
				bookingError: "Booking error",
				temporaryUnavailable: "We cannot process your request right now. Please try again later.",
				invalidPhone: "Invalid phone number",
				rentalTermsEndpointMissing: "Rental terms endpoint not configured.",
				rentalTermsError: "Could not load rental terms.",
				bookingSessionExpired: "The session has expired."
			},
		};

		const merged = this._deepMerge(defaults, raw);

		if (cfgLang) {
			merged.lang = cfgLang;
		}

		if (cfgLocale) {
			merged.locale = cfgLocale;
		}

		return merged;
	}

	_t(path, fallback = "") {
		const v = this._pick(this.i18n, path);
		if (typeof v === "string" && v.trim() !== "") return v;
		return String(fallback || "");
	}

	_deepMerge(base, extra) {
		const out = Array.isArray(base) ? base.slice() : { ...base };
		if (!extra || typeof extra !== "object") return out;

		Object.keys(extra).forEach((k) => {
			const bv = out[k];
			const ev = extra[k];

			if (bv && typeof bv === "object" && !Array.isArray(bv) && ev && typeof ev === "object" && !Array.isArray(ev)) {
				out[k] = this._deepMerge(bv, ev);
			} else {
				out[k] = ev;
			}
		});

		return out;
	}

	_toInt(v, def) {
		const n = parseInt(String(v ?? "").trim(), 10);
		return Number.isNaN(n) ? def : n;
	}

	_getMaxChildrenForPeople(people) {
		const totalPeople = Math.max(1, this._toInt(people ?? this.state.form.people ?? 1, 1));
		return Math.max(0, totalPeople - 1);
	}

	_getCurrentPeopleValue() {
		const selectValue = this.stepContainer?.querySelector('select[data-md-field="people"]')?.value;
		const people = this._toInt(selectValue ?? this.state.form.people ?? 1, 1);
		this.state.form.people = Math.max(1, people);
		return this.state.form.people;
	}

	_getClampedChildrenCount(people) {
		if (this.cfg.showChildrenIncluded !== true || !this.state.form.children_included) {
			return 0;
		}

		const maxChildren = this._getMaxChildrenForPeople(people);
		const children = this._toInt(this.state.form.pax_children || 0, 0);
		return Math.max(0, Math.min(children, maxChildren));
	}

	_syncChildrenSelectOptions() {
		const peopleVal = this._getCurrentPeopleValue();
		const maxChildren = this._getMaxChildrenForPeople(peopleVal);
		const current = this._getClampedChildrenCount(peopleVal);

		this.state.form.pax_children = current;

		const sel = this.stepContainer?.querySelector('select[data-md-field="pax_children"]');
		if (!sel) {
			return;
		}

		let html = `<option value="0" ${current === 0 ? "selected" : ""}>0</option>`;
		for (let i = 1; i <= maxChildren; i++) {
			html += `<option value="${i}" ${current === i ? "selected" : ""}>${i}</option>`;
		}

		sel.innerHTML = html;
		sel.value = String(current);
	}

	_toFloat(v, def) {
		if (v == null) return def;
		if (typeof v === "number") return Number.isFinite(v) ? v : def;
		const s = String(v).trim().replace(/\s/g, "").replace(",", ".");
		const n = parseFloat(s);
		return Number.isNaN(n) ? def : n;
	}

	_esc(s) {
		return String(s || "")
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	_extractPaymentUrl(result) {
		if (!result) return "";
		if (typeof result.url_payment === "string") return result.url_payment;
		if (typeof result.redirect_url === "string") return result.redirect_url;
		if (typeof result.url_booking === "string") return result.url_booking;
		if (result.data && typeof result.data.url_payment === "string") return result.data.url_payment;
		if (result.data && typeof result.data.redirect_url === "string") return result.data.redirect_url;

		if (result.build_payment && typeof result.build_payment === "object") {
			const u = this._extractPaymentUrl(result.build_payment);
			if (u) return u;
		}
		return "";
	}

	_pick(obj, path) {
		if (!obj || typeof obj !== "object") return null;
		const keys = String(path).split(".");
		let v = obj;
		for (let i = 0; i < keys.length; i++) {
			if (v && typeof v === "object" && v[keys[i]] !== undefined) v = v[keys[i]];
			else return null;
		}
		return v;
	}

	_getFreeAdditionalLabel() {
		return this.cfg?.freeAdditionalLabel === "included"
			? this._t("labels.included", "Included")
			: this._t("ui.free", "Free");
	}

	_readBoatIdFromTopPanel() {
		try {
			const topDoc = window.top && window.top.document ? window.top.document : null;
			if (!topDoc) return "";
			const el = topDoc.querySelector("#maradigma_cpt_boat_id");
			if (el && el.value) return String(el.value || "").trim();
		} catch { }
		return "";
	}

	_wpJsonBase() {
		const g = this.globalCfg;
		const base = String(g.wpJsonBase || "/wp-json").trim();
		return base || "/wp-json";
	}

	_buildBoatEndpoint(boatId, query = {}) {
		const wpJson = this._wpJsonBase();
		const base = this.api.joinUrl(wpJson, "maradigma/v1/boats/" + encodeURIComponent(String(boatId)));

		const parts = [];
		Object.keys(query).forEach((k) => {
			const v = query[k];
			if (v == null) return;
			const s = String(v).trim();
			if (!s) return;
			parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(s));
		});

		if (!parts.length) return base;
		return base + (base.includes("?") ? "&" : "?") + parts.join("&");
	}

	_unwrapBoatResponse(raw) {
		if (!raw || typeof raw !== "object") return raw;
		if (raw.success === true && raw.data && typeof raw.data === "object") return raw.data;
		if (raw.data && typeof raw.data === "object") return raw.data;
		return raw;
	}

	_extractBoatCapacity(boatData) {
		if (!boatData) return null;
		const c = boatData.boat_capacity ?? boatData.capacity ?? boatData.pax ?? boatData.max_people ?? boatData.people_max ?? null;
		const n = this._toInt(c, 0);
		return n > 0 ? n : null;
	}

	_getCfg() {
		const calendarDisplayRaw = String(this.wrapper.getAttribute("data-calendar-display") || "popup").trim().toLowerCase();
		const calendarSelectionModeRaw = String(this.wrapper.getAttribute("data-calendar-selection-mode") || "range").trim().toLowerCase();
		const freeAdditionalLabelRaw = String(this.wrapper.getAttribute("data-free-additional-label") || "free").trim().toLowerCase();

		let calendarDisplay = ["popup", "inline"].includes(calendarDisplayRaw) ? calendarDisplayRaw : "popup";
		let calendarSelectionMode = ["range", "single"].includes(calendarSelectionModeRaw) ? calendarSelectionModeRaw : "range";
		let freeAdditionalLabel = ["free", "included"].includes(freeAdditionalLabelRaw) ? freeAdditionalLabelRaw : "free";

		let calendarMonths = this._toInt(this.wrapper.getAttribute("data-calendar-months") || 2, 2);
		if (![1, 2].includes(calendarMonths)) {
			calendarMonths = 2;
		}

		if (calendarDisplay === "inline") {
			calendarMonths = 1;
		}

		return {
			restNonce: "",

			priceOnBookingEndpoint:
				this.wrapper.getAttribute("data-price-on-booking-endpoint") ||
				this.globalCfg.restUrlBoatPriceOnBooking ||
				this.api.joinUrl(this._wpJsonBase(), "maradigma/v1/boat/price-on-booking"),

			bookingOnlineEndpoint:
				this.wrapper.getAttribute("data-booking-online-endpoint") ||
				this.globalCfg.restUrlBookingOnline ||
				this.api.joinUrl(this._wpJsonBase(), "maradigma/v1/booking/online"),

			rentalTermsEndpoint:
				this.wrapper.getAttribute("data-rental-terms-endpoint") ||
				this.globalCfg.restUrlRentalTerms ||
				this.api.joinUrl(this._wpJsonBase(), "maradigma/v1/booking/rental-terms"),

			boatId: this.wrapper.getAttribute("data-boat-id") || "",
			expand: this.wrapper.getAttribute("data-expand") || this.globalCfg.expand || "",
			lang: this.wrapper.getAttribute("data-lang") || this.globalCfg.lang || this.globalCfg.default_language || "",

			countriesEndpoint:
				this.wrapper.getAttribute("data-countries-endpoint") ||
				this.globalCfg.restUrlCountries ||
				this.api.joinUrl(this._wpJsonBase(), "maradigma/v1/countries"),

			calendarDisplay,
			calendarMonths,
			calendarSelectionMode,

			showScheduleText: this.wrapper.getAttribute("data-show-schedule-text") !== "0",
			showPromoCode: this.wrapper.getAttribute("data-show-promo-code") !== "0",
			showChildrenIncluded: this.wrapper.getAttribute("data-show-children-included") !== "0",
			freeAdditionalLabel,
		};
	}

	_applyDefaultDatesOnOpen() {
		const ds = String(this.state.form.date_start || "").trim();
		const de = String(this.state.form.date_end || "").trim();
		if (ds && de) return;

		const def = this._computeDefaultDateYmdMadrid();
		if (!def) return;

		this.state.form.date_start = def;
		this.state.form.date_end = def;
	}

	_computeDefaultDateYmdMadrid() {
		const today = this._computeTodayYmdMadrid();
		if (!today) return "";

		const dtf = new Intl.DateTimeFormat("en-GB", {
			timeZone: "Europe/Madrid",
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
			hour: "2-digit",
			minute: "2-digit",
			hour12: false,
		});

		const parts = dtf.formatToParts(new Date());
		const map = {};
		parts.forEach((p) => {
			if (p && p.type) map[p.type] = p.value;
		});

		const hour = this._toInt(map.hour, 0);

		const base = new Date(`${today}T00:00:00`);
		if (hour >= 12) base.setDate(base.getDate() + 1);

		return this._toYmd(base);
	}

	_computeTodayYmdMadrid() {
		const dtf = new Intl.DateTimeFormat("en-GB", {
			timeZone: "Europe/Madrid",
			year: "numeric",
			month: "2-digit",
			day: "2-digit",
		});

		const parts = dtf.formatToParts(new Date());
		const map = {};
		parts.forEach((p) => {
			if (p && p.type) map[p.type] = p.value;
		});

		const year = this._toInt(map.year, 0);
		const month = this._toInt(map.month, 0);
		const day = this._toInt(map.day, 0);

		if (!year || !month || !day) return "";

		const pad2 = (n) => (n < 10 ? "0" : "") + String(n);
		return year + "-" + pad2(month) + "-" + pad2(day);
	}

	_isYmdBefore(value, minDate) {
		const ymd = String(value || "").trim();
		const min = String(minDate || "").trim();

		if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd) || !/^\d{4}-\d{2}-\d{2}$/.test(min)) {
			return false;
		}

		return ymd < min;
	}

	_extractSidebarBoatData() {
		const b = this.state.boat_data || {};

		const title = b.service_name || b.name || b.boat_name || b.title || "";
		const port = b.boat_base_port_name || b.base_port_name || b.port || "";

		let imageUrl = "";
		if (b.image_url) imageUrl = b.image_url;
		if (!imageUrl && Array.isArray(b.images) && b.images[0]?.url) imageUrl = b.images[0].url;
		if (!imageUrl && Array.isArray(b.service_images) && b.service_images[0]?.url) imageUrl = b.service_images[0].url;
		if (!imageUrl && Array.isArray(b.service_images) && b.service_images[0]?.url_main_domain) imageUrl = b.service_images[0].url_main_domain;

		return { title, port, imageUrl };
	}

	_extractSidebarQuoteData() {
		const raw = this.state.quote;

		const isCalc = raw && typeof raw === "object" && (raw.is_calculating === true || raw.is_calculating === "1");

		if (isCalc) {
			const base = this._lastQuoteOk && typeof this._lastQuoteOk === "object" ? this._lastQuoteOk : raw;
			const normalized = this._normalizeQuoteData(base);
			return { ...normalized, isCalculating: true };
		}

		if (!raw || typeof raw !== "object") return { additionals: [], additionalsAvailable: [], isCalculating: false };

		return this._normalizeQuoteData(raw);
	}

	_normalizeQuoteData(raw) {
		if (!raw || typeof raw !== "object") {
			return {
				additionals: [],
				additionalsAvailable: [],
				isCalculating: false,
			};
		}

		const d = (() => {
			if (raw.status === "success" && raw.data && typeof raw.data === "object") return raw.data;
			if (raw.success === true && raw.data && typeof raw.data === "object") return raw.data;
			if (raw.data && typeof raw.data === "object") return raw.data;
			return raw;
		})();

		const currency = this._pick(d, "currency") || this.globalCfg.currency || "EUR";

		const grandTotalValue =
			this._toFloat(this._pick(d, "amounts.grand_total.total"), null) ??
			this._toFloat(this._pick(d, "amounts.service.total"), null) ??
			null;

		const grandBaseValue =
			this._toFloat(this._pick(d, "amounts.grand_total.base"), null) ??
			this._toFloat(this._pick(d, "amounts.service.base"), null) ??
			null;

		const grandVatValue =
			this._toFloat(this._pick(d, "amounts.grand_total.vat"), null) ??
			this._toFloat(this._pick(d, "amounts.service.vat"), null) ??
			null;

		const cardCommissionEnabled =
			this._pick(d, "payment.card_commission.enabled") === true;

		const commissionFromGrand = this._pick(d, "amounts.grand_total.commission") || null;
		const commissionLine = Array.isArray(d?.lines)
			? d.lines.find((line) => String(line?.type || "") === "commission")
			: null;

		const onlineCommissionBase =
			this._toFloat(this._pick(d, "amounts.grand_total.commission.base"), null) ??
			this._toFloat(this._pick(commissionLine, "amounts.base"), null) ??
			0;

		const onlineCommissionVat =
			this._toFloat(this._pick(d, "amounts.grand_total.commission.vat"), null) ??
			this._toFloat(this._pick(commissionLine, "amounts.vat"), null) ??
			0;

		const onlineCommissionTotal =
			this._toFloat(this._pick(d, "amounts.grand_total.commission.total"), null) ??
			this._toFloat(this._pick(commissionLine, "amounts.total"), null) ??
			0;

		const hasOnlineCommission =
			cardCommissionEnabled === true &&
			onlineCommissionTotal > 0;

		const totalFormatted =
			this._pick(d, "amounts.grand_total.formatted.total") ||
			this._pick(d, "amounts.grand_total.formatted.grand_total") ||
			this._pick(d, "amounts.service.formatted.total") ||
			(grandTotalValue != null ? this._formatMoney(grandTotalValue, currency) : null);

		const vatFormatted =
			this._pick(d, "amounts.grand_total.formatted.vat") ||
			this._pick(d, "amounts.service.formatted.vat") ||
			(grandVatValue != null ? this._formatMoney(grandVatValue, currency) : null);

		const vatPercentNumeric =
			this._toFloat(this._pick(d, "amounts.grand_total.vat_percent"), null) ??
			this._toFloat(this._pick(d, "amounts.service.vat_percent"), null) ??
			this._toFloat(this._pick(d, "amounts.vat_percent"), null) ??
			null;

		const vatPercentLabel =
			this._pick(d, "amounts.grand_total.formatted.vat_percent") ||
			this._pick(d, "amounts.service.formatted.vat_percent") ||
			(vatPercentNumeric != null && vatPercentNumeric > 0 ? `${vatPercentNumeric}%` : null);

		const taxableAmountFormatted =
			grandBaseValue != null
				? this._formatMoney(grandBaseValue, currency)
				: null;

		const rentalPriceFormatted =
			this._pick(d, "amounts.service.formatted.base") ||
			this._pick(d, "amounts.service.formatted.total") ||
			null;

		const rentalPriceBaseFormatted =
			this._pick(d, "amounts.service.formatted.base") ||
			null;

		const rentalPriceVatFormatted =
			this._pick(d, "amounts.service.formatted.vat") ||
			null;

		const additionalsTotalFormatted =
			this._pick(d, "amounts.additionals.formatted.total") ||
			null;

		let prepaymentPercent =
			this._toFloat(this._pick(d, "prepayment.percent"), null) ??
			this._toFloat(this._pick(d, "prepayment.prepayment_percent"), null) ??
			null;

		const payOnlineFormatted =
			this._pick(d, "prepayment.due_now.formatted.total") ||
			null;

		const payOnlineBaseFormatted =
			this._pick(d, "prepayment.due_now.formatted.base") ||
			null;

		const payOnlineVatFormatted =
			this._pick(d, "prepayment.due_now.formatted.vat") ||
			null;

		const payOnSpotFormatted =
			this._pick(d, "prepayment.due_later.formatted.total") ||
			null;

		const payOnSpotBaseFormatted =
			this._pick(d, "prepayment.due_later.formatted.base") ||
			null;

		const payOnSpotVatFormatted =
			this._pick(d, "prepayment.due_later.formatted.vat") ||
			null;

		const paymentDefaultMethod =
			this._pick(d, "payment.default_method") ||
			this._pick(d, "default_method") ||
			null;

		const cardCommissionPercent =
			this._toFloat(this._pick(d, "payment.card_commission.percent"), null);

		const cardCommissionMode =
			this._pick(d, "payment.card_commission.mode") || "";

		const cardCommissionIncludedIn =
			this._pick(d, "payment.card_commission.included_in") || "";

		const onlineCommissionFormatted =
			this._pick(d, "amounts.grand_total.commission.formatted.total") ||
			this._pick(commissionLine, "amounts.formatted.total") ||
			(onlineCommissionTotal > 0 ? this._formatMoney(onlineCommissionTotal, currency) : null);

		const onlineCommissionBaseFormatted =
			this._pick(d, "amounts.grand_total.commission.formatted.base") ||
			this._pick(commissionLine, "amounts.formatted.base") ||
			(onlineCommissionBase > 0 ? this._formatMoney(onlineCommissionBase, currency) : null);

		const onlineCommissionVatFormatted =
			this._pick(d, "amounts.grand_total.commission.formatted.vat") ||
			this._pick(commissionLine, "amounts.formatted.vat") ||
			(onlineCommissionVat > 0 ? this._formatMoney(onlineCommissionVat, currency) : null);

		const lang = String(this.cfg?.lang || "EN").toUpperCase();
		const freeAdditionalLabel = this._getFreeAdditionalLabel();

		const additionalsRaw = this._pick(d, "additionals") || [];
		const additionals = Array.isArray(additionalsRaw)
			? additionalsRaw.map((x) => {
				const optionalType = this._toInt(x?.optional_type ?? x?.id_optional_type ?? x?.optionalType ?? 0, 0);
				const idOptionalService = this._toInt(x?.id_optional_service ?? x?.idOptionalService ?? x?.optional_service_id ?? 0, 0);
				const idOnGi = this._toInt(x?.id_additional_on_gi ?? x?.id ?? 0, 0);

				const name =
					(x?.translations && typeof x.translations === "object" && x.translations[lang]) ||
					x?.name ||
					x?.names?.additional ||
					"—";

				const priceBaseNum = this._toFloat(this._pick(x, "amounts.base"), 0) || 0;
				const priceSubtotalNum =
					this._toFloat(this._pick(x, "amounts.subtotal"), null) ??
					priceBaseNum;
				const priceTotalNum = this._toFloat(this._pick(x, "amounts.total"), 0) || 0;

				const priceBaseFormattedRaw =
					this._pick(x, "amounts.formatted.base") ||
					"";

				const priceSubtotalFormattedRaw =
					this._pick(x, "amounts.formatted.subtotal") ||
					"";

				const priceTotalFormattedRaw =
					this._pick(x, "amounts.formatted.total") ||
					x?.price_format ||
					x?.pformat_total ||
					"";

				const priceBaseFormatted =
					priceBaseNum <= 0
						? freeAdditionalLabel
						: priceBaseFormattedRaw || this._formatMoney(priceBaseNum, currency);

				const priceSubtotalFormatted =
					priceSubtotalNum <= 0
						? freeAdditionalLabel
						: priceSubtotalFormattedRaw || this._formatMoney(priceSubtotalNum, currency);

				const priceFormatted =
					priceTotalNum <= 0
						? freeAdditionalLabel
						: priceTotalFormattedRaw || this._formatMoney(priceTotalNum, currency);

				let badge = "";
				if (optionalType === 1) badge = this._t("labels.mandatory", "Mandatory");
				else if (optionalType === 3) badge = this._t("labels.included", "Included");

				return {
					id: idOnGi,
					idOptionalService,
					optionalType,
					name,
					badge,
					priceFormatted,
					priceBaseFormatted,
					priceSubtotalFormatted,
					priceBaseNum,
					priceSubtotalNum,
					priceTotalNum,
					payment: this._toInt(x?.payment, 0),
					amounts: x?.amounts || {},
					translations: x?.translations || null,
				};
			})
			: [];

		const additionalsAvailableRaw = this._pick(d, "additionals_available") || [];
		const additionalsAvailable = Array.isArray(additionalsAvailableRaw)
			? additionalsAvailableRaw.map((x) => {
				const optionalType = this._toInt(x?.optional_type ?? 0, 0);
				const idOptionalService = this._toInt(x?.id_optional_service ?? 0, 0);
				const idOnGi = this._toInt(x?.id_additional_on_gi ?? x?.id ?? 0, 0);

				const name =
					(x?.translations && typeof x.translations === "object" && x.translations[lang]) ||
					x?.name ||
					x?.names?.additional ||
					"—";

				const priceBaseNum = this._toFloat(this._pick(x, "amounts.base"), 0) || 0;
				const priceSubtotalNum =
					this._toFloat(this._pick(x, "amounts.subtotal"), null) ??
					priceBaseNum;
				const priceTotalNum = this._toFloat(this._pick(x, "amounts.total"), 0) || 0;

				const priceBaseFormattedRaw =
					this._pick(x, "amounts.formatted.base") ||
					"";

				const priceSubtotalFormattedRaw =
					this._pick(x, "amounts.formatted.subtotal") ||
					"";

				const priceTotalFormattedRaw =
					this._pick(x, "amounts.formatted.total") ||
					"";

				const priceBaseFormatted =
					priceBaseNum <= 0
						? freeAdditionalLabel
						: priceBaseFormattedRaw || this._formatMoney(priceBaseNum, currency);

				const priceSubtotalFormatted =
					priceSubtotalNum <= 0
						? freeAdditionalLabel
						: priceSubtotalFormattedRaw || this._formatMoney(priceSubtotalNum, currency);

				const priceFormatted =
					priceTotalNum <= 0
						? freeAdditionalLabel
						: priceTotalFormattedRaw || this._formatMoney(priceTotalNum, currency);

				let badge = "";
				if (optionalType === 1) badge = this._t("labels.mandatory", "Mandatory");
				else if (optionalType === 3) badge = this._t("labels.included", "Included");

				return {
					id: idOnGi,
					idOptionalService,
					optionalType,
					name,
					badge,
					priceFormatted,
					priceBaseFormatted,
					priceSubtotalFormatted,
					priceBaseNum,
					priceSubtotalNum,
					priceTotalNum,
					payment: this._toInt(x?.payment, 0),
					amounts: x?.amounts || {},
					translations: x?.translations || null,
				};
			})
			: [];

		if (prepaymentPercent != null) {
			if (prepaymentPercent < 0) prepaymentPercent = 0;
			if (prepaymentPercent > 100) prepaymentPercent = 100;
		}

		return {
			totalFormatted,
			vatFormatted,
			vatPercentLabel,
			taxableAmountFormatted,
			rentalPriceFormatted,
			rentalPriceBaseFormatted,
			rentalPriceVatFormatted,
			additionalsTotalFormatted,
			payOnlineFormatted,
			payOnlineBaseFormatted,
			payOnlineVatFormatted,
			payOnSpotFormatted,
			payOnSpotBaseFormatted,
			payOnSpotVatFormatted,
			prepaymentPercent,
			additionals,
			additionalsAvailable,
			paymentDefaultMethod,
			hasOnlineCommission,
			onlineCommissionFormatted,
			onlineCommissionBaseFormatted,
			onlineCommissionVatFormatted,
			onlineCommissionPercent: cardCommissionPercent,
			onlineCommissionMode: cardCommissionMode,
			onlineCommissionIncludedIn: cardCommissionIncludedIn,
			subtotalBeforeCommissionValue: null,
			subtotalBeforeCommissionFormatted: null,
			isCalculating: false,
		};
	}

	_getQuotePayload() {
		const raw = this.state?.quote || null;

		if (raw && raw.status === "success" && raw.data && typeof raw.data === "object") {
			return raw.data;
		}

		if (raw && raw.success === true && raw.data && typeof raw.data === "object") {
			return raw.data;
		}

		if (raw && raw.data && typeof raw.data === "object") {
			return raw.data;
		}

		return raw && typeof raw === "object" ? raw : {};
	}

	_getBreakdownData() {
		const payload = this._getQuotePayload();
		const currency = this._pick(payload, "currency") || this.globalCfg.currency || "EUR";

		const serviceLine = Array.isArray(payload?.lines)
			? payload.lines.find((line) => String(line?.type || "") === "service")
			: null;

		const additionals = Array.isArray(payload?.additionals) ? payload.additionals : [];

		const prepaymentPercent = this._toFloat(payload?.prepayment?.percent, 0) || 0;
		const pendingPercent = this._toFloat(payload?.prepayment?.pending_percent, 0) || 0;

		const dueNow = payload?.prepayment?.due_now || {};
		const dueLater = payload?.prepayment?.due_later || {};

		const dueNowCommission = dueNow?.commission || null;
		const dueLaterCommission = dueLater?.commission || null;

		const serviceBase = this._toFloat(serviceLine?.amounts?.base, 0) || 0;

		const nowServiceBase = serviceBase > 0
			? (serviceBase * prepaymentPercent) / 100
			: 0;

		const laterServiceBase = serviceBase > 0
			? (serviceBase * pendingPercent) / 100
			: 0;

		const onlineAdditionals = additionals.filter((item) => this._toInt(item?.payment, 0) === 1);
		const portAdditionals = additionals.filter((item) => this._toInt(item?.payment, 0) === 2);

		const nowCommissionBase = this._toFloat(dueNowCommission?.base, 0) || 0;
		const nowCommissionVat = this._toFloat(dueNowCommission?.vat, 0) || 0;
		const nowCommissionTotal = this._toFloat(dueNowCommission?.total, 0) || 0;

		const laterCommissionBase = this._toFloat(dueLaterCommission?.base, 0) || 0;
		const laterCommissionVat = this._toFloat(dueLaterCommission?.vat, 0) || 0;
		const laterCommissionTotal = this._toFloat(dueLaterCommission?.total, 0) || 0;

		const nowBaseRaw = this._toFloat(dueNow?.base, 0) || 0;
		const nowVatRaw = this._toFloat(dueNow?.vat, 0) || 0;
		const nowTotalRaw = this._toFloat(dueNow?.total, 0) || 0;

		const laterBaseRaw = this._toFloat(dueLater?.base, 0) || 0;
		const laterVatRaw = this._toFloat(dueLater?.vat, 0) || 0;
		const laterTotalRaw = this._toFloat(dueLater?.total, 0) || 0;

		return {
			prepaymentPercent,
			pendingPercent,
			vatPercent: this._toFloat(payload?.amounts?.vat_percent, 0) || 0,
			serviceLine,

			now: {
				serviceBase: nowServiceBase,
				additionals: onlineAdditionals,
				base: nowBaseRaw,
				vat: nowVatRaw,
				total: nowTotalRaw,
				commissionBase: nowCommissionBase,
				commissionVat: nowCommissionVat,
				commissionTotal: nowCommissionTotal,
				baseFormatted: dueNow?.formatted?.base || this._formatMoney(nowBaseRaw, currency),
				vatFormatted: dueNow?.formatted?.vat || this._formatMoney(nowVatRaw, currency),
				totalFormatted: dueNow?.formatted?.total || this._formatMoney(nowTotalRaw, currency),
				commissionFormatted:
					dueNowCommission?.formatted?.total ||
					this._formatMoney(nowCommissionTotal, currency),
				commissionPercent: this._toFloat(dueNowCommission?.percent, 0) || 0,
				serviceBaseFormatted: this._formatMoney(nowServiceBase, currency),
			},

			later: {
				serviceBase: laterServiceBase,
				additionals: portAdditionals,
				base: laterBaseRaw,
				vat: laterVatRaw,
				total: laterTotalRaw,
				commissionBase: laterCommissionBase,
				commissionVat: laterCommissionVat,
				commissionTotal: laterCommissionTotal,
				baseFormatted: dueLater?.formatted?.base || this._formatMoney(laterBaseRaw, currency),
				vatFormatted: dueLater?.formatted?.vat || this._formatMoney(laterVatRaw, currency),
				totalFormatted: dueLater?.formatted?.total || this._formatMoney(laterTotalRaw, currency),
				commissionFormatted:
					dueLaterCommission?.formatted?.total ||
					this._formatMoney(laterCommissionTotal, currency),
				commissionPercent: this._toFloat(dueLaterCommission?.percent, 0) || 0,
				serviceBaseFormatted: this._formatMoney(laterServiceBase, currency),
			},
		};
	}

	_getAdditionalDisplayName(additional) {
		if (!additional || typeof additional !== "object") {
			return "—";
		}

		const lang = String(this.cfg?.lang || "EN").toUpperCase();

		return (
			(additional?.translations && additional.translations[lang]) ||
			additional?.name ||
			"—"
		);
	}

	_renderBreakdownAdditionalRows(additionals = []) {
		if (!Array.isArray(additionals) || !additionals.length) {
			return "";
		}

		return additionals.map((item) => {
			const label = this._getAdditionalDisplayName(item);

			const numericAmount =
				this._toFloat(item?.amounts?.base, null) ??
				this._toFloat(item?.amounts?.subtotal, null) ??
				this._toFloat(item?.amounts?.total, null) ??
				0;

			const amount =
				item?.amounts?.formatted?.base ||
				item?.amounts?.formatted?.subtotal ||
				item?.amounts?.formatted?.total ||
				this._formatMoney(numericAmount);

			return `
      <div class="md-side__breakdown-row md-side__breakdown-row--product">
        <div class="md-side__breakdown-label">${this._esc(label)}</div>
        <div class="md-side__breakdown-value">${this._esc(amount)}</div>
      </div>
    `;
		}).join("");
	}

	_renderPayNowBreakdownPanel() {
		const data = this._getBreakdownData();

		if (!data?.now?.total || data.now.total <= 0) {
			return "";
		}

		const percentLabel = data.prepaymentPercent > 0
			? `${this._esc(String(data.prepaymentPercent))}% ${this._t("labels.rentalPrice", "Rental price")}`
			: this._t("labels.rentalPrice", "Rental price");

		const serviceAmount = data.now.serviceBaseFormatted;

		const vatLabel = data.vatPercent > 0
			? `${this._t("labels.vat", "VAT")} (${this._esc(String(data.vatPercent))}%)`
			: this._t("labels.vat", "VAT");

		const commissionRow = data.now.commissionBase > 0
			? `
      <div class="md-side__breakdown-row md-side__breakdown-row--product">
        <div class="md-side__breakdown-label">
          <div>${this._t("labels.serviceFee", "Service fee")}</div>
          ${data.now.commissionPercent > 0
				? `<div class="md-side__breakdown-note">${this._esc(String(data.now.commissionPercent))}% ${this._t("labels.appliedToAmountYouWillPayNow", "applied to the amount you will pay now")}</div>`
				: ""}
        </div>
        <div class="md-side__breakdown-value">${this._esc(data.now.commissionBaseFormatted || this._formatMoney(data.now.commissionBase))}</div>
      </div>
    `
			: "";

		return `
    <div class="md-side__breakdown" data-md-toggle-panel="pay-now-breakdown" data-md-open="0">
      <div class="md-side__breakdown-list">
        <div class="md-side__breakdown-group md-side__breakdown-group--products">
          <div class="md-side__breakdown-row md-side__breakdown-row--product">
            <div class="md-side__breakdown-label">${percentLabel}</div>
            <div class="md-side__breakdown-value">${this._esc(serviceAmount)}</div>
          </div>

          ${this._renderBreakdownAdditionalRows(data.now.additionals)}
          ${commissionRow}
        </div>

        <div class="md-side__breakdown-separator"></div>

        <div class="md-side__breakdown-group md-side__breakdown-group--summary">
          <div class="md-side__breakdown-row md-side__breakdown-row--summary">
            <div class="md-side__breakdown-label">${this._t("labels.taxableAmount", "Taxable amount")}</div>
            <div class="md-side__breakdown-value">${this._esc(data.now.baseFormatted)}</div>
          </div>

          <div class="md-side__breakdown-row md-side__breakdown-row--summary">
            <div class="md-side__breakdown-label">${vatLabel}</div>
            <div class="md-side__breakdown-value">${this._esc(data.now.vatFormatted)}</div>
          </div>
        </div>

        <div class="md-side__breakdown-separator"></div>

        <div class="md-side__breakdown-row md-side__breakdown-row--total">
          <div class="md-side__breakdown-label">${this._t("labels.total", "Total")}</div>
          <div class="md-side__breakdown-value">${this._esc(data.now.totalFormatted)}</div>
        </div>
      </div>
    </div>
  `;
	}

	_renderPayLaterBreakdownPanel() {
		const data = this._getBreakdownData();

		if (!data?.later?.total || data.later.total <= 0) {
			return "";
		}

		const percentLabel = data.pendingPercent > 0
			? `${this._esc(String(data.pendingPercent))}% ${this._t("labels.rentalPrice", "Rental price")}`
			: this._t("labels.rentalPrice", "Rental price");

		const serviceAmount = data.later.serviceBaseFormatted;

		const vatLabel = data.vatPercent > 0
			? `${this._t("labels.vat", "VAT")} (${this._esc(String(data.vatPercent))}%)`
			: this._t("labels.vat", "VAT");

		const commissionRow = data.later.commissionTotal > 0
			? `
      <div class="md-side__breakdown-row md-side__breakdown-row--product">
        <div class="md-side__breakdown-label">
          ${this._t("labels.serviceFee", "Service fee")}
          ${data.later.commissionPercent > 0
				? `<span class="md-side__breakdown-note-inline">(${this._esc(String(data.later.commissionPercent))}%)</span>`
				: ""}
          <span class="md-side__breakdown-note-inline">(${this._t("labels.taxIncluded", "VAT incl.")})</span>
        </div>
        <div class="md-side__breakdown-value">${this._esc(data.later.commissionFormatted)}</div>
      </div>
    `
			: "";

		return `
    <div class="md-side__breakdown" data-md-toggle-panel="pay-later-breakdown" data-md-open="0">
      <div class="md-side__breakdown-list">
        <div class="md-side__breakdown-group md-side__breakdown-group--products">
          <div class="md-side__breakdown-row md-side__breakdown-row--product">
            <div class="md-side__breakdown-label">${percentLabel}</div>
            <div class="md-side__breakdown-value">${this._esc(serviceAmount)}</div>
          </div>

          ${this._renderBreakdownAdditionalRows(data.later.additionals)}
          ${commissionRow}
        </div>

        <div class="md-side__breakdown-separator"></div>

        <div class="md-side__breakdown-group md-side__breakdown-group--summary">
          <div class="md-side__breakdown-row md-side__breakdown-row--summary">
            <div class="md-side__breakdown-label">${this._t("labels.taxableAmount", "Taxable amount")}</div>
            <div class="md-side__breakdown-value">${this._esc(data.later.baseFormatted)}</div>
          </div>

          <div class="md-side__breakdown-row md-side__breakdown-row--summary">
            <div class="md-side__breakdown-label">${vatLabel}</div>
            <div class="md-side__breakdown-value">${this._esc(data.later.vatFormatted)}</div>
          </div>
        </div>

        <div class="md-side__breakdown-separator"></div>

        <div class="md-side__breakdown-row md-side__breakdown-row--total">
          <div class="md-side__breakdown-label">${this._t("labels.total", "Total")}</div>
          <div class="md-side__breakdown-value">${this._esc(data.later.totalFormatted)}</div>
        </div>
      </div>
    </div>
  `;
	}

	_syncPaymentDefaultsFromQuote(raw) {
		if (!raw || typeof raw !== "object") return;

		const d =
			raw.status === "success" && raw.data && typeof raw.data === "object"
				? raw.data
				: raw.success === true && raw.data && typeof raw.data === "object"
					? raw.data
					: raw.data && typeof raw.data === "object"
						? raw.data
						: raw;

		const dm =
			d?.default_method ||
			d?.payment?.default_method ||
			d?.api?.payment?.default_method ||
			null;

		if (!dm || typeof dm !== "object") return;

		if (!this.state.api) this.state.api = {};
		if (!this.state.api.payment) this.state.api.payment = {};

		this.state.api.payment.default_method = {
			key: String(dm.key || "").trim(),
			name: String(dm.name || "").trim(),
			group: String(dm.group || "").trim(),
		};

		const k = this.state.api.payment.default_method.key;
		if (k && (!this.state.form.payment_method || this.state.form.payment_method === "card")) {
			this.state.form.payment_method = k;
		}
	}

	_extractBoatAdditionalsForUI() {
		const b = this.state.boat_data || {};
		const arr = Array.isArray(b.additional_services) ? b.additional_services : [];
		const lang = String(this.cfg?.lang || "EN").toUpperCase();
		const freeAdditionalLabel = this._getFreeAdditionalLabel();

		return arr
			.map((x) => {
				const optionalType = this._toInt(x.optional_type ?? 0, 0);
				const idOptionalService = this._toInt(x.id_optional_service ?? 0, 0);
				const idOnGi = this._toInt(x.id_additional_on_gi ?? x.id ?? x.id_on_gi ?? 0, 0);

				const name =
					(x?.translations && typeof x.translations === "object" && x.translations[lang]) ||
					x?.names?.additional ||
					x?.name ||
					"—";

				const numericPrice =
					this._toFloat(x?.prices?.total, null) ??
					this._toFloat(x?.prices?.base, null) ??
					this._toFloat(x?.total_price, null) ??
					this._toFloat(x?.price, null) ??
					0;

				const priceFormattedRaw =
					x?.prices?.pformat?.total ||
					x?.prices?.pformat?.base ||
					x?.total_price ||
					x?.price ||
					"";

				const priceFormatted =
					numericPrice <= 0
						? freeAdditionalLabel
						: priceFormattedRaw;

				let badge = "";
				if (optionalType === 1) badge = this._t("labels.mandatory", "Mandatory");
				else if (optionalType === 3) badge = this._t("labels.included", "Included");

				return { id: idOnGi, idOptionalService, optionalType, name, badge, priceFormatted };
			})
			.filter((x) => this._toInt(x.id, 0) > 0);
	}

	_getSelectedAdditionalsIds() {
		const m = this.state?.form?.additionals_selected || {};
		return Object.keys(m)
			.filter((k) => !!m[k])
			.map((k) => this._toInt(k, 0))
			.filter(Boolean)
			.sort((a, b) => a - b);
	}

	_isSkipperSelectedByUser() {
		const selectedOnGi = new Set(this._getSelectedAdditionalsIds());
		const q = this._extractSidebarQuoteData();
		const all = Array.isArray(q?.additionalsAvailable) ? q.additionalsAvailable : [];
		return all.some((a) => this._toInt(a.idOptionalService, 0) === 5 && selectedOnGi.has(this._toInt(a.id, 0)));
	}

	_getOptionalSkipperOnGiInfo() {
		const q = this._extractSidebarQuoteData();
		const all = Array.isArray(q?.additionalsAvailable) ? q.additionalsAvailable : [];

		const skipper = all.find((a) => this._toInt(a.idOptionalService, 0) === 5 && this._toInt(a.optionalType, 0) === 2);
		if (!skipper) return null;

		const idOnGi = this._toInt(skipper.id, 0);
		if (!idOnGi) return null;

		return { idOnGi };
	}

	_isDateWithinRange(dateYmd, startYmd, endYmd) {
		if (!dateYmd || !startYmd || !endYmd) return false;
		return dateYmd >= startYmd && dateYmd <= endYmd;
	}

	_getActivePriceRangeFromPriceOnBooking() {
		const raw = this.state?.quote || null;

		const payload =
			raw && raw.status === "success" && raw.data && typeof raw.data === "object"
				? raw.data
				: raw && raw.success === true && raw.data && typeof raw.data === "object"
					? raw.data
					: raw && raw.data && typeof raw.data === "object"
						? raw.data
						: raw;

		const dateStart = String(this.state?.form?.date_start || payload?.period?.date_start || "").trim();
		const ranges = payload?.boat?.price_ranges;

		if (!dateStart || !Array.isArray(ranges)) return null;

		for (const r of ranges) {
			if (!r || !r.date_start || !r.date_end) continue;
			if (this._isDateWithinRange(dateStart, r.date_start, r.date_end)) return r;
		}

		return null;
	}

	_getBoatUnavailabilityData() {
		const boatData = this.state?.boat_data || {};

		const raw =
			boatData?.unavailability_dates_and_timeslots ||
			boatData?.service_unavailability_dates ||
			boatData?.unavailability ||
			null;

		if (!raw || typeof raw !== "object") {
			return {
				dates: [],
				time_slots: [],
			};
		}

		return {
			dates: Array.isArray(raw.dates) ? raw.dates : [],
			time_slots: Array.isArray(raw.time_slots) ? raw.time_slots : [],
		};
	}

	_getFlatpickrDisabledDates() {
		const unavailability = this._getBoatUnavailabilityData();
		const disabledDates = [];

		const addDate = (ymd) => {
			const value = String(ymd || "").trim();

			if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
				return;
			}

			if (!disabledDates.includes(value)) {
				disabledDates.push(value);
			}
		};

		if (Array.isArray(unavailability.dates)) {
			unavailability.dates.forEach(addDate);
		}

		if (Array.isArray(unavailability.time_slots)) {
			unavailability.time_slots.forEach((row) => {
				const slotId = this._toInt(row?.id_time_slot ?? 0, 0);

				if (slotId <= 0) {
					addDate(row?.date);
				}
			});
		}

		return disabledDates;
	}

	_getUnavailableTimeSlotsForDate(dateYmd) {
		const targetDate = String(dateYmd || "").trim();
		if (!targetDate) {
			return [];
		}

		const unavailability = this._getBoatUnavailabilityData();
		if (!Array.isArray(unavailability.time_slots)) {
			return [];
		}

		return unavailability.time_slots.filter((row) => {
			return String(row?.date || "").trim() === targetDate;
		});
	}

	_normalizeClockTime(value) {
		const match = String(value || "").trim().match(/^(\d{1,2}):(\d{2})/);
		if (!match) return "";
		return String(match[1]).padStart(2, "0") + ":" + match[2];
	}

	_isTimeSlotBlocked(timeSlot, blockedTimeSlot) {
		const timeSlotId = this._toInt(timeSlot?.id ?? timeSlot?.id_time_slot ?? 0, 0);
		const blockedId = this._toInt(blockedTimeSlot?.id_time_slot ?? blockedTimeSlot?.id ?? 0, 0);

		if (timeSlotId > 0 && blockedId > 0 && timeSlotId === blockedId) {
			return true;
		}

		const timeSlotStart = this._normalizeClockTime(timeSlot?.start_time ?? timeSlot?.time_start);
		const timeSlotEnd = this._normalizeClockTime(timeSlot?.time_end ?? timeSlot?.end_time);
		const blockedStart = this._normalizeClockTime(blockedTimeSlot?.time_start ?? blockedTimeSlot?.start_time);
		const blockedEnd = this._normalizeClockTime(blockedTimeSlot?.time_end ?? blockedTimeSlot?.end_time);

		if (!timeSlotStart || !blockedStart || timeSlotStart !== blockedStart) {
			return false;
		}

		return !timeSlotEnd || !blockedEnd || timeSlotEnd === blockedEnd;
	}

	_getAvailableTimeSlotsForDate(dateYmd, rangeTimeslots) {
		const timeslots = Array.isArray(rangeTimeslots) ? rangeTimeslots : [];
		const unavailableTimeSlots = this._getUnavailableTimeSlotsForDate(dateYmd);

		return timeslots.filter((timeSlot) => {
			return !unavailableTimeSlots.some((blockedTimeSlot) => {
				return this._isTimeSlotBlocked(timeSlot, blockedTimeSlot);
			});
		});
	}

	_canBookFullDay(rangeTimeslots, availableTimeslots) {
		const allTimeslots = Array.isArray(rangeTimeslots) ? rangeTimeslots : [];
		const available = Array.isArray(availableTimeslots) ? availableTimeslots : [];

		return (
			allTimeslots.length > 0 &&
			available.length === allTimeslots.length &&
			allTimeslots.every((timeSlot) => timeSlot?.compatible_with_day_rental === true)
		);
	}

	_renderTimeslotSelectorIfNeeded() {
		const ds = String(this.state?.form?.date_start || "").trim();
		const de = String(this.state?.form?.date_end || "").trim();
		const isMultiDay = !!(ds && de && ds !== de);

		if (isMultiDay) {
			this.state.form.full_day = true;
			this.state.form.timeslot_id = null;
			this.state.form.timeslot = null;
			return "";
		}

		const activeRange = this._getActivePriceRangeFromPriceOnBooking();
		const rangeTimeslots = Array.isArray(activeRange?.timeslots) ? activeRange.timeslots : [];
		const timeslots = this._getAvailableTimeSlotsForDate(ds, rangeTimeslots);

		if (rangeTimeslots.length === 0) {
			this.state.form.timeslot_id = null;
			this.state.form.timeslot = null;
			this.state.form.full_day = false;
			return "";
		}

		const selectedTimeslotIsAvailable = timeslots.some((timeSlot) => {
			return String(timeSlot?.id || "") === String(this.state.form.timeslot_id || "");
		});

		if (this.state.form.timeslot_id && !selectedTimeslotIsAvailable) {
			this.state.form.timeslot_id = null;
			this.state.form.timeslot = null;
		}

		const canFullDay = this._canBookFullDay(rangeTimeslots, timeslots);

		if (!canFullDay) {
			this.state.form.full_day = false;
			return this._renderTimeslotSelect(rangeTimeslots, { showHelp: true });
		}

		const fullDayChecked = this.state.form.full_day === true;

		if (fullDayChecked) {
			this.state.form.timeslot_id = null;
			this.state.form.timeslot = null;

			return this._renderFullDayToggle({
				checked: true,
				showHelp: true,
				showSelect: false,
				selectHtml: "",
			});
		}

		const selectHtml = this._renderTimeslotSelect(rangeTimeslots, { showHelp: false });

		return this._renderFullDayToggle({
			checked: false,
			showHelp: true,
			showSelect: true,
			selectHtml,
		});
	}

	_renderFullDayToggle({ checked, showHelp = true, showSelect = true, selectHtml = "" } = {}) {
		const label = this._t("labels.fullDayCharter", "Full day charter");
		const help = this._t(
			"labels.fullDayCharterHelp",
			"Select full day if you want the boat for the entire day. Otherwise, choose a half-day schedule."
		);

		const isChecked = checked === true || checked === 1 || checked === "1" || checked === "true";

		return `
    <div class="md-col-12 md-field" data-md-fullday-wrap="1">
      <label class="md-checkline">
        <input type="checkbox" data-md-field="full_day" ${isChecked ? "checked" : ""}>
        <span class="md-checkline__text">
          <span class="md-checkline__title">${this._esc(label)}</span>
          ${showHelp ? `<small class="md-muted md-help">${this._esc(help)}</small>` : ""}
        </span>
      </label>

      <div data-md-timeslot-select-wrap="1" style="${showSelect ? "" : "display:none;"}">
        ${selectHtml || ""}
      </div>
    </div>
  `;
	}

	_renderTimeslotSelect(timeslots, { showHelp = true } = {}) {
		const label = this._t("labels.selectTimeslot", "Select a schedule");
		const help = this._t(
			"labels.selectTimeslotHelp",
			"Choose your preferred half-day schedule for this day charter."
		);
		const occupiedLabel = this._t("ui.occupied", "Occupied");
		const current = this.state.form.timeslot_id ? String(this.state.form.timeslot_id) : "";
		const selectedDate = String(this.state?.form?.date_start || "").trim();
		const unavailable = this._getUnavailableTimeSlotsForDate(selectedDate);
		const groupSeed = String(this.modal?.id || this.cfg?.boatId || "booking").replace(/[^a-zA-Z0-9_-]/g, "_");
		const groupName = `md_timeslot_${groupSeed}`;

		const cardsHtml = timeslots
			.map((timeSlot) => {
				const id = timeSlot?.id;
				if (!id) return "";

				const isOccupied = unavailable.some((blockedTimeSlot) => {
					return this._isTimeSlotBlocked(timeSlot, blockedTimeSlot);
				});
				const isSelected = !isOccupied && current !== "" && String(id) === current;
				const name = String(timeSlot?.name || this._buildTimeslotLabel(timeSlot, { useAmPm: false }) || label);
				const timeRange = this._formatTimeslotRange(timeSlot?.start_time, timeSlot?.duration, {
					useAmPm: true,
				});
				const accessibleLabel = [name, timeRange, isOccupied ? occupiedLabel : ""].filter(Boolean).join(", ");

				return `
        <label class="md-timeslot-card${isOccupied ? " is-occupied" : ""}">
          <input
            class="md-timeslot-card__input"
            type="radio"
            name="${this._esc(groupName)}"
            value="${this._esc(String(id))}"
            data-md-field="timeslot_id"
            aria-label="${this._esc(accessibleLabel)}"
            ${isSelected ? "checked" : ""}
            ${isOccupied ? "disabled" : ""}
          >
          <span class="md-timeslot-card__body">
            <span class="md-timeslot-card__indicator" aria-hidden="true"></span>
            <span class="md-timeslot-card__content">
              <span class="md-timeslot-card__name">${this._esc(name)}</span>
              ${timeRange ? `<span class="md-timeslot-card__time">${this._esc(timeRange)}</span>` : ""}
            </span>
            ${isOccupied ? `<span class="md-timeslot-card__status">${this._esc(occupiedLabel)}</span>` : ""}
          </span>
        </label>`;
			})
			.join("");

		return `
    <fieldset class="md-field md-timeslots" data-md-timeslot-options="1">
      <legend class="md-timeslots__legend">${this._esc(label)}</legend>
      ${showHelp ? `<p class="md-timeslots__help">${this._esc(help)}</p>` : ""}
      <div class="md-timeslots__grid">
        ${cardsHtml}
      </div>
    </fieldset>
  `;
	}

	_formatTimeslotRange(startTime, durationMinutes, { useAmPm = false } = {}) {
		if (!startTime || !durationMinutes) return null;

		const parts = String(startTime).split(":");
		const h = Number(parts[0] || 0);
		const m = Number(parts[1] || 0);

		const startTotal = h * 60 + m;
		const endTotal = startTotal + Number(durationMinutes || 0);

		const startH = Math.floor(startTotal / 60) % 24;
		const startM = startTotal % 60;

		const endH = Math.floor(endTotal / 60) % 24;
		const endM = endTotal % 60;

		const pad2 = (n) => String(n).padStart(2, "0");

		if (!useAmPm) {
			return `${pad2(startH)}:${pad2(startM)} - ${pad2(endH)}:${pad2(endM)}`;
		}

		const locale = (document.documentElement.lang || "en").toLowerCase();
		const mk = (hh, mm) => {
			const d = new Date();
			d.setHours(hh, mm, 0, 0);
			return new Intl.DateTimeFormat(locale, { hour: "numeric", minute: "2-digit" }).format(d);
		};
		return `${mk(startH, startM)} - ${mk(endH, endM)}`;
	}

	_buildTimeslotLabel(ts, { useAmPm = false } = {}) {
		const name = ts?.name || ts?.slot_type || "";
		const range = this._formatTimeslotRange(ts?.start_time, ts?.duration, { useAmPm });
		return range ? `${name} (${range})` : String(name || "");
	}

	_getCurrencyForUi() {
		const q = this.state.quote || {};
		const d = q && typeof q === "object" && q.data && typeof q.data === "object" ? q.data : q;
		return this._pick(d, "currency") || this.globalCfg.currency || "EUR";
	}

	_formatMoney(amount, currency = "EUR") {
		const n = this._toFloat(amount, null);
		if (n == null) return "";

		const locale = this._getUiLocale();

		try {
			return new Intl.NumberFormat(locale, {
				style: "currency",
				currency: currency || "EUR",
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			}).format(n);
		} catch {
			return String(n.toFixed(2)) + " " + (currency || "EUR");
		}
	}

	_computeFuelUi() {
		const boat = this.state.boat_data || {};
		const opt = this._toInt(boat.boat_fuel_included_option, -1);

		if (opt === 0) {
			return {
				show: true,
				title: this._t("labels.fuelNotIncluded", "Fuel not included"),
				info: "",
				learnMoreLabel: this._t("ui.learnMore", "Learn more"),
				learnMoreText: this._t("ui.fuelLearnMore", "Fuel cost may be charged before or after boarding."),
				showLearnMore: true,
			};
		}

		if (opt === 1) {
			return {
				show: true,
				title: this._t("ui.fuelIncluded", "Fuel included"),
				info: this._t("ui.fuelIncludedInfo", "Fuel is already included in the rental price, so you won’t need to pay it separately."),
				learnMoreLabel: "",
				learnMoreText: "",
				showLearnMore: false,
			};
		}

		return { show: false };
	}

	_computeSecurityDepositUi() {
		const boat = this.state.boat_data || {};
		const currency = this._getCurrencyForUi();

		const skipperOpt = this._toInt(boat.boat_skipper_option, -1);
		const depositWith = this._toFloat(boat.boat_deposit_with_captain, 0);
		const depositWithout = this._toFloat(boat.boat_deposit_without_captain, 0);
		const depositGeneric = this._toFloat(boat.boat_security_deposit, 0);

		const pformat = boat.pformat_deposits || boat.pformat?.deposits || null;
		const pfWith = pformat?.with_skipper || pformat?.with_captain || null;
		const pfWithout = pformat?.without_skipper || pformat?.without_captain || null;
		const pfGeneric = pformat?.deposit || pformat?.security_deposit || null;

		const pickGeneric = () => {
			if (pfGeneric) return String(pfGeneric);
			if (depositGeneric > 0) return this._formatMoney(depositGeneric, currency);
			return "";
		};

		const pickWithCaptain = () => {
			if (depositWith > 0) return pfWith ? String(pfWith) : this._formatMoney(depositWith, currency);
			return pickGeneric();
		};

		const pickWithoutCaptain = () => {
			if (depositWithout > 0) return pfWithout ? String(pfWithout) : this._formatMoney(depositWithout, currency);
			return pickGeneric();
		};

		let formatted = "";
		if (skipperOpt === 0) formatted = pickWithCaptain();
		else if (skipperOpt === 1) formatted = pickWithoutCaptain();
		else if (skipperOpt === 2) formatted = this._isSkipperSelectedByUser() ? pickWithCaptain() : pickWithoutCaptain();
		else formatted = pickGeneric();

		if (!formatted) return { show: false };

		return {
			show: true,
			title: this._t("labels.securityDeposit", "Amount of the security deposit"),
			formatted,
			info: "",
		};
	}

	_formatPassengers(n) {
		const v = this._toInt(n, 1);
		const singular = this._t("labels.passengerSingular", "passenger");
		const plural = this._t("labels.passengerPlural", "passengers");

		return v === 1 ? `1 ${singular}` : `${v} ${plural}`;
	}

	_getUiLocale() {
		const candidates = [
			this.i18n?.locale,
			this.cfg?.lang,
			document.documentElement.getAttribute("lang"),
			navigator.language,
			"en-GB",
		];

		for (const candidate of candidates) {
			const normalized = this._normalizeLocale(candidate);
			if (normalized) {
				return normalized;
			}
		}

		return "en-GB";
	}

	_normalizeLocale(value) {
		const raw = String(value || "").trim();
		if (!raw) return "";

		const cleaned = raw.replace(/_/g, "-");

		try {
			const [resolved] = Intl.DateTimeFormat.supportedLocalesOf([cleaned]);
			if (resolved) return resolved;
		} catch { }

		const base = cleaned.split("-")[0]?.toLowerCase() || "";
		if (!base) return "";

		try {
			const [resolvedBase] = Intl.DateTimeFormat.supportedLocalesOf([base]);
			if (resolvedBase) return resolvedBase;
		} catch { }

		return "";
	}

	_formatDateHumanLong(ymd, locale = this._getUiLocale()) {
		const s = String(ymd || "").trim();
		if (!s) return "";

		const d = new Date(`${s}T00:00:00`);
		if (Number.isNaN(d.getTime())) return s;

		try {
			return new Intl.DateTimeFormat(locale, {
				day: "numeric",
				month: "long",
				year: "numeric",
			}).format(d);
		} catch {
			return s;
		}
	}

	_formatDateRangeHuman(ds, de) {
		const s = String(ds || "").trim();
		const e = String(de || "").trim();

		if (!s && !e) {
			return this._t("ui.notAvailable", "—");
		}

		const locale = this._getUiLocale();
		const sPretty = s ? this._formatDateHumanLong(s, locale) : "";
		const ePretty = e ? this._formatDateHumanLong(e, locale) : "";

		if (s && e && s === e) {
			return sPretty || s;
		}

		if (s && e) {
			const template = this._t("labels.dateRangeBetween", "From {start} to {end}");
			return template
				.replace("%start%", sPretty || s)
				.replace("%end%", ePretty || e)
				.replace("{start}", sPretty || s)
				.replace("{end}", ePretty || e);
		}

		return sPretty || ePretty || s || e || this._t("ui.notAvailable", "—");
	}

	_getSelectedScheduleRangeText() {
		const q = this.state?.quote || null;

		const payload =
			q && q.status === "success" && q.data && typeof q.data === "object"
				? q.data
				: q && q.success === true && q.data && typeof q.data === "object"
					? q.data
					: q && q.data && typeof q.data === "object"
						? q.data
						: q;

		const normHm = (t) => {
			const s = String(t || "").trim();
			if (!s) return "";
			const m = s.match(/^(\d{1,2}):(\d{2})/);
			if (!m) return s;
			return `${String(m[1]).padStart(2, "0")}:${m[2]}`;
		};

		const ts = this.state?.form?.timeslot || null;
		if (ts && ts.start_time && ts.duration) {
			const range = this._formatTimeslotRange(ts.start_time, ts.duration, { useAmPm: false });
			if (range) return range;
		}

		const sch = payload?.boat?.schedules || payload?.schedules || {};

		const checkin =
			sch.checkin ||
			sch.check_in ||
			sch.checkin_time ||
			sch.start ||
			sch.start_time ||
			"";

		const checkout =
			sch.checkout ||
			sch.check_out ||
			sch.checkout_time ||
			sch.end ||
			sch.end_time ||
			"";

		const a = normHm(checkin);
		const b = normHm(checkout);

		if (a && b) return `${a} - ${b}`;

		return "";
	}

	_toYmd(date) {
		const d = date instanceof Date ? date : new Date(date);
		const yyyy = d.getFullYear();
		const mm = String(d.getMonth() + 1).padStart(2, "0");
		const dd = String(d.getDate()).padStart(2, "0");
		return `${yyyy}-${mm}-${dd}`;
	}

	_svg(key) {
		if (
			window.MaradigmaIcons &&
			typeof window.MaradigmaIcons.renderIcon === "function"
		) {
			return `<span class="md-ico" aria-hidden="true">${window.MaradigmaIcons.renderIcon(key, {
				className: "md-ico__svg",
				width: 16,
				height: 16
			})}</span>`;
		}

		const svgs = window.MaradigmaConfig && window.MaradigmaConfig.svgs ? window.MaradigmaConfig.svgs : {};
		const raw = svgs && (svgs[key] || svgs[`svg-${key}`]) ? String(svgs[key] || svgs[`svg-${key}`]) : "";
		if (!raw) return "";

		return `<span class="md-ico" aria-hidden="true">${raw}</span>`;
	}

	static boot(selector = ".md-booking") {
		if (!window.__MD_BOOKING_GLOBAL_OPEN_BOUND__) {
			window.__MD_BOOKING_GLOBAL_OPEN_BOUND__ = true;

			document.addEventListener(
				"click",
				(e) => {
					const openBtn = e?.target?.closest?.("[data-md-open]");
					if (!openBtn) {
						return;
					}

					const wrapper = openBtn.closest(".md-booking");
					if (!wrapper) {
						return;
					}

					let instance = wrapper.__mdBookingInstance || null;

					if (!instance) {
						instance = new MaradigmaBookingModal(wrapper);
						wrapper.__mdBookingInstance = instance;

						void instance.init().then(() => {
							instance.handleOpenTrigger(e);
						});

						return;
					}

					instance.handleOpenTrigger(e);
				},
				true
			);
		}

		const wrappers = document.querySelectorAll(selector);
		if (!wrappers || !wrappers.length) return;

		wrappers.forEach((wrapper) => {
			if (wrapper?.getAttribute?.("data-md-modal-portal") === "body") {
				return;
			}

			if (wrapper.__mdBookingInstance) {
				return;
			}

			const modal = new MaradigmaBookingModal(wrapper);
			wrapper.__mdBookingInstance = modal;
			void modal.init();
		});
	}
}

if (document.readyState === "loading") {
	document.addEventListener("DOMContentLoaded", () => MaradigmaBookingModal.boot());
} else {
	MaradigmaBookingModal.boot();
}

window.MaradigmaBookingModal = MaradigmaBookingModal;
