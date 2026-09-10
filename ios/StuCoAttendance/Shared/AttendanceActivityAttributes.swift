import ActivityKit

struct AttendanceActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable { var present: Int; var total: Int; var isOpen: Bool }
    var eventID: Int
    var eventTitle: String
    var eventDate: String
}
