import type { Entry } from './entry';

/**
 * Phase 1 Part B (17 Oct 2026): units of measure with a dimension, recipe
 * lines written in any convertible unit, conversion rows with a purpose, and
 * the stock-unit lock said plainly on the product form.
 */
export const units = {
    'units.dimension': { en: 'Measures', bn: 'কী মাপে' },
    'units.dimension.mass': { en: 'Mass (g, kg)', bn: 'ভর (গ্রাম, কেজি)' },
    'units.dimension.volume': { en: 'Volume (mL, L)', bn: 'আয়তন (মিলি, লিটার)' },
    'units.dimension.count': { en: 'Count (per product)', bn: 'সংখ্যা (পণ্য অনুযায়ী)' },
    'units.dimension.none': { en: 'Not classified', bn: 'শ্রেণিভুক্ত নয়' },
    'units.dimension.hint': {
        en: 'g and kg, mL and L convert by themselves. A count unit (pcs, jar, box) converts only through a conversion recorded for one product.',
        bn: 'গ্রাম-কেজি আর মিলি-লিটার নিজে থেকেই রূপান্তর হয়। সংখ্যার একক (পিস, জার, বক্স) কেবল কোনো একটি পণ্যের জন্য লেখা রূপান্তর দিয়েই বদলায়।',
    },

    'units.recipe.unit': { en: 'Unit', bn: 'একক' },
    'units.recipe.in_stock': { en: '= {quantity} {unit} of stock', bn: '= স্টকে {quantity} {unit}' },
    'units.recipe.unit_hint': {
        en: "Only units the ERP can turn into the component's stock unit are offered. g and kg, mL and L convert by themselves; any other unit needs a recipe conversion for that product first (Inventory > Setup > Purchase units).",
        bn: 'শুধু সেই এককগুলোই দেখানো হয় যেগুলো ইআরপি উপাদানটির স্টক এককে বদলাতে পারে। গ্রাম-কেজি, মিলি-লিটার নিজে থেকেই বদলায়; অন্য একক লাগলে আগে ওই পণ্যের জন্য রেসিপি রূপান্তর লিখতে হবে (ইনভেন্টরি > সেটআপ > ক্রয় একক)।',
    },

    'units.stock_unit.label': { en: 'Stock unit', bn: 'স্টক একক' },
    'units.stock_unit.locked_note': { en: 'Locked after the first stock movement', bn: 'প্রথম স্টক মুভমেন্টের পর আর বদলানো যায় না' },
    'units.stock_unit.locked_now': {
        en: 'Fixed: this product already has stock movements, so its stock unit cannot change. Create a new product for another unit.',
        bn: 'স্থির: এই পণ্যের স্টক মুভমেন্ট আছে, তাই এর স্টক একক আর বদলানো যাবে না। অন্য একক লাগলে নতুন পণ্য তৈরি করুন।',
    },
    'units.fractional.label': {
        en: 'This count unit may be split (for example 0.5 pcs on a recipe line)',
        bn: 'এই সংখ্যার একক ভাগ করা যাবে (যেমন রেসিপি লাইনে 0.5 পিস)',
    },
    'units.fractional.hint': {
        en: 'Off by default: a product counted in pieces, jars or boxes is consumed in whole numbers.',
        bn: 'সাধারণত বন্ধ থাকে: পিস, জার বা বক্সে গোনা পণ্য পুরো সংখ্যায় খরচ হয়।',
    },

    'units.purpose': { en: 'Used for', bn: 'যে কাজে' },
    'units.purpose.purchase': { en: 'Purchase (goods receipt)', bn: 'ক্রয় (মাল গ্রহণ)' },
    'units.purpose.recipe': { en: 'Recipe (production)', bn: 'রেসিপি (উৎপাদন)' },
    'units.purpose.sale': { en: 'Sale', bn: 'বিক্রয়' },
    'units.purpose.hint': {
        en: 'A purchase conversion is what every goods receipt applies and needs both directions typed. A recipe conversion lets a recipe line be written in this unit; it is one direction only, exactly as stated here, and the ERP never works out the reverse by itself.',
        bn: 'ক্রয় রূপান্তর প্রতিটি মাল গ্রহণে প্রয়োগ হয় এবং দুই দিকই লিখতে হয়। রেসিপি রূপান্তর দিয়ে রেসিপির লাইন এই এককে লেখা যায়; এটি কেবল এক দিকের, ঠিক যেমন এখানে লেখা, উল্টো দিকটি ইআরপি নিজে থেকে কখনও হিসাব করে না।',
    },
    'units.conversion.recipe_direction': {
        en: 'One {from} of this product is how many {to} (its stock unit)?',
        bn: 'এই পণ্যের এক {from} = কত {to} (এর স্টক একক)?',
    },
    'units.conversion.same_dimension': {
        en: 'g and kg, mL and L convert by themselves and never need a row here.',
        bn: 'গ্রাম-কেজি আর মিলি-লিটার নিজে থেকেই রূপান্তর হয়, এখানে কোনো সারি লাগে না।',
    },
} satisfies Record<string, Entry>;
