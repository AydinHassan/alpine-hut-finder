export interface Night {
    date: string;
    freeBeds: number;
    percentage: string | null;
}

export interface Hut {
    id: number;
    name: string;
    club: string | null;
    lat: number;
    lng: number;
    altitude: number | null;
    totalBeds: number | null;
    website: string | null;
    bookingUrl: string;
    nights: Night[];
}

export interface ManualHut {
    id: number;
    name: string;
    club: string | null;
    lat: number;
    lng: number;
    altitude: number | null;
    phone: string | null;
    email: string | null;
    website: string | null;
}

export interface ManualHutView extends ManualHut {
    distance: number | null;
}

export interface Payload {
    huts: Hut[];
    manualHuts: ManualHut[];
    days: number;
    today: string;
    updatedAt: string | null;
}

/** A hut with derived UI fields for the current filter/origin. */
export interface HutView extends Hut {
    distance: number | null;
    night: Night | null;
    maxFree: number;
    freeNow: number;
}
