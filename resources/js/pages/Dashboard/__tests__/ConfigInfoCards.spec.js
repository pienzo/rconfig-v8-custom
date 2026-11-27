import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import ConfigInfoCards from "@/pages/Dashboard/ConfigInfoCards.vue";

const { push } = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock("vue-router", () => ({
	useRouter: () => ({ push }),
}));

function mountCards(data, isLoadingConfiginfo = false) {
	return mount(ConfigInfoCards, {
		props: { configinfo: { data }, isLoadingConfiginfo },
		global: {
			provide: { formatters: { formatTime: (value) => value } },
			stubs: { RcDashboardHovercard: true, Skeleton: true },
		},
	});
}

describe("Dashboard device count cards", () => {
	it.each([
		{ deviceCount: 0, deviceDownCount: 0 },
		{ deviceCount: 12, deviceDownCount: 3 },
	])("keeps solid semantic colors with counts %j", (data) => {
		const wrapper = mountCards(data);
		const total = wrapper.get('[data-testid="device-count-card"]');
		const down = wrapper.get('[data-testid="down-devices-card"]');

		expect(total.element.style.backgroundColor).toBe("rgb(22, 101, 52)");
		expect(down.element.style.backgroundColor).toBe("rgb(185, 28, 28)");

		for (const card of [total, down]) {
			expect(card.element.style.color).toBe("rgb(255, 255, 255)");
			expect(card.element.style.backgroundImage).toBe("");
			expect(card.find(".absolute.inset-0").exists()).toBe(false);
			expect(card.classes()).not.toContain("hover:bg-muted/20");
		}

		expect(total.get(".text-2xl").text()).toBe(String(data.deviceCount));
		expect(down.get(".text-2xl").text()).toBe(String(data.deviceDownCount));
		expect(wrapper.findAll(".grid > div")).toHaveLength(6);
	});

	it("retains both colors when data is still loading", () => {
		const wrapper = mountCards(undefined, true);
		const total = wrapper.get('[data-testid="device-count-card"]');
		const down = wrapper.get('[data-testid="down-devices-card"]');

		expect(total.element.style.backgroundColor).toBe("rgb(22, 101, 52)");
		expect(down.element.style.backgroundColor).toBe("rgb(185, 28, 28)");
		expect(total.find(".text-2xl").exists()).toBe(false);
		expect(down.find(".text-2xl").exists()).toBe(false);
	});

	it("preserves navigation to all devices and down devices", async () => {
		push.mockClear();
		const wrapper = mountCards({ deviceCount: 12, deviceDownCount: 3 });

		await wrapper.get('[data-testid="device-count-card"]').trigger("click");
		await wrapper.get('[data-testid="down-devices-card"]').trigger("click");

		expect(push.mock.calls).toEqual([
			[{ name: "devices" }],
			[{ name: "devices", query: { statusId: 0 } }],
		]);
	});
});
