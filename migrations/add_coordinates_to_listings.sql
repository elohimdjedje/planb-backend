-- Migration: Add latitude/longitude coordinates to listings table
-- This enables precise map-based geolocation for all listings

ALTER TABLE listings ADD COLUMN latitude DOUBLE NULL DEFAULT NULL AFTER address;
ALTER TABLE listings ADD COLUMN longitude DOUBLE NULL DEFAULT NULL AFTER latitude;

-- Index for spatial queries (nearby search)
CREATE INDEX idx_listing_coordinates ON listings (latitude, longitude);
