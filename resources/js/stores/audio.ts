import { create } from 'zustand';

interface AudioStore {
    currentId: string | null;
    setCurrentId: (id: string | null) => void;
}

export const useAudioStore = create<AudioStore>((set) => ({
    currentId: null,
    setCurrentId: (id) => set({ currentId: id }),
}));
