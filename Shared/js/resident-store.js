/**
 * Local resident registry for static / no-backend use.
 * Syncs with FrontPage registration and resident login (registration is immediate; no admin queue).
 */
(function () {
  const STORAGE_KEY = "bc_residents";

  function loadAll() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch {
      return [];
    }
  }

  function saveAll(list) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
  }

  function normalizeIdNumber(value) {
    return String(value || "").trim().toUpperCase().replace(/[\s-]+/g, "");
  }

  function buildIdentityKey(data) {
    const norm = (s) => String(s || "").toLowerCase().trim().replace(/\s+/g, " ");
    const extra = data.profileExtra && typeof data.profileExtra === "object" ? data.profileExtra : {};
    const nameParts = [
      norm(data.firstname),
      norm(data.middlename),
      norm(data.lastname),
      norm(extra.suffix),
    ].filter(Boolean);
    const addrParts = [
      norm(data.street),
      norm(data.barangay),
      norm(data.city),
      norm(data.province),
    ].filter(Boolean);
    return `${nameParts.join("|")}||${data.birthdate || ""}||${addrParts.join("|")}`;
  }

  function stripUser(r) {
    if (!r) return null;
    return {
      id: r.id,
      firstname: r.firstname,
      lastname: r.lastname,
      phoneNum: r.phoneNum,
      email: r.email || "",
      status: r.status,
      approvedDateTime: r.approvedDateTime || null,
    };
  }

  window.BarangayConnectResidents = {
    register: function (data) {
      const firstname = (data.firstname || "").trim();
      const lastname = (data.lastname || "").trim();
      const phoneNum = String(data.phoneNum || "").trim();
      const email = (data.email || "").trim();
      const password = data.password || "";
      const username = (data.username || "").trim();

      const list = loadAll();
      const extra = data.profileExtra && typeof data.profileExtra === "object" ? data.profileExtra : {};
      const barangayId = normalizeIdNumber(extra.barangayIdNumber);
      const nationalId = normalizeIdNumber(extra.nationalIdNumber);
      const voterId = normalizeIdNumber(extra.voterIdNumber);
      const identityKey = buildIdentityKey(data);

      if (list.some((r) => r.phoneNum === phoneNum)) {
        throw new Error("DUPLICATE_PHONE");
      }
      const em = email.toLowerCase();
      if (em && list.some((r) => (r.email || "").toLowerCase() === em)) {
        throw new Error("DUPLICATE_EMAIL");
      }
      if (
        username &&
        list.some(
          (r) =>
            (r.username || "").toLowerCase() === username.toLowerCase()
        )
      ) {
        throw new Error("DUPLICATE_USERNAME");
      }
      if (barangayId && list.some((r) => normalizeIdNumber((r.profileExtra || {}).barangayIdNumber) === barangayId)) {
        throw new Error("DUPLICATE_BARANGAY_ID");
      }
      if (nationalId && list.some((r) => normalizeIdNumber((r.profileExtra || {}).nationalIdNumber) === nationalId)) {
        throw new Error("DUPLICATE_NATIONAL_ID");
      }
      if (voterId && list.some((r) => normalizeIdNumber((r.profileExtra || {}).voterIdNumber) === voterId)) {
        throw new Error("DUPLICATE_VOTER_ID");
      }
      if (identityKey && list.some((r) => buildIdentityKey(r) === identityKey)) {
        throw new Error("DUPLICATE_IDENTITY");
      }

      const id =
        "bc_" + Date.now() + "_" + Math.random().toString(36).slice(2, 10);
      const resident = {
        id,
        firstname,
        lastname,
        phoneNum,
        email,
        password,
        status: "ACTIVE",
        approvedDateTime: new Date().toISOString(),
      };
      if (username) resident.username = username;
      const mid = (data.middlename || "").trim();
      if (mid) resident.middlename = mid;
      if (data.gender) resident.gender = String(data.gender).trim();
      if (data.birthdate) resident.birthdate = String(data.birthdate).trim();
      if (data.civilStatus) resident.civilStatus = String(data.civilStatus).trim();
      if (data.age != null && data.age !== "") resident.age = data.age;
      if (data.street) resident.addressStreet = String(data.street).trim();
      if (data.purok) resident.addressPurok = String(data.purok).trim();
      if (data.barangay) resident.addressBarangay = String(data.barangay).trim();
      if (data.city) resident.addressCity = String(data.city).trim();
      if (data.province) resident.addressProvince = String(data.province).trim();
      if (data.profileExtra && typeof data.profileExtra === "object") {
        resident.profileExtra = data.profileExtra;
      }

      list.push(resident);
      saveAll(list);
      return stripUser(resident);
    },

    login: function (identifier, password) {
      const idRaw = String(identifier || "").trim();
      const digits = idRaw.replace(/\D/g, "");
      const list = loadAll();
      let r = list.find((x) => x.phoneNum === idRaw);
      if (!r && digits.length >= 10) {
        r = list.find((x) => x.phoneNum === digits);
      }
      if (!r && idRaw) {
        const low = idRaw.toLowerCase();
        r = list.find((x) => (x.email || "").toLowerCase() === low);
      }
      if (!r || r.password !== password) return null;
      if (r.status === "REJECTED") return null;
      return stripUser(r);
    },

    getById: function (id) {
      const list = loadAll();
      const r = list.find((x) => x.id === id);
      return stripUser(r);
    },

    listPending: function () {
      return [];
    },

    listApproved: function () {
      return loadAll()
        .filter((r) => r.status !== "REJECTED")
        .map((r) => {
          const parts = [
            r.addressStreet,
            r.addressBarangay,
            r.addressCity,
            r.addressProvince,
          ]
            .map((x) => (x != null ? String(x).trim() : ""))
            .filter(Boolean);
          return {
            id: r.id,
            residentId: r.residentId || r.resident_id || r.id,
            firstname: r.firstname,
            lastname: r.lastname,
            username: r.username || "",
            addressInfo: parts.length ? parts.join(", ") : "—",
            phoneNum: r.phoneNum,
            email: r.email || "",
            isLoggedIn: false,
            approvedDateTime: r.approvedDateTime || null,
          };
        });
    },

    approve: function (id) {
      const list = loadAll();
      const r = list.find((x) => x.id === id);
      if (!r) return false;
      r.status = "ACTIVE";
      r.approvedDateTime = new Date().toISOString();
      saveAll(list);
      return true;
    },

    reject: function (id) {
      const list = loadAll();
      const r = list.find((x) => x.id === id);
      if (!r) return false;
      r.status = "REJECTED";
      saveAll(list);
      return true;
    },

    counts: function () {
      const list = loadAll();
      const registered = list.filter((x) => x.status !== "REJECTED").length;
      return {
        totalUsers: registered,
        activeUsers: registered,
        offlineUsers: 0,
        pendingUsers: 0,
      };
    },
  };
})();
