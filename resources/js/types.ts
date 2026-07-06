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

export interface Payload {
    huts: Hut[];
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
